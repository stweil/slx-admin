<?php

class Util
{

	/**
	 * Displays an error message and stops script execution.
	 * If CONFIG_DEBUG is true, it will also dump a stack trace
	 * and all globally defined variables.
	 * (As this might reveal sensistive data you should never enable it in production)
	 */
	public static function traceError($message)
	{
		Header('Content-Type: text/plain; charset=utf-8');
		echo "--------------------\nFlagrant system error:\n$message\n--------------------\n\n";
		if (defined('CONFIG_DEBUG') && CONFIG_DEBUG) {
			debug_print_backtrace();
			echo "\n\nSome variables for your entertainment:\n";
			print_r($GLOBALS);
		}
		exit(0);
	}

	/**
	 * Redirects the user via a '302 Moved' header.
	 * An active session will be saved, any messages that haven't
	 * been displayed yet will be appended to the redirect.
	 * @param string $location Location to redirect to. "false" to redirect to same URL (useful after POSTs)
	 */
	public static function redirect($location = false)
	{
		if ($location === false) {
			$location = preg_replace('/(&|\?)message\[\]\=[^&]*(&|$)/', '\1', $_SERVER['REQUEST_URI']);
		}
		Session::save();
		$messages = Message::toRequest();
		if (!empty($messages)) {
			if (strpos($location, '?') === false) {
				$location .= '?' . $messages;
			} else {
				$location .= '&' . $messages;
			}
		}
		Header('Location: ' . $location);
		exit(0);
	}

	/**
	 * Verify the user's token that protects agains CSRF.
	 * If the user is logged in and there is no token variable set in
	 * the request, or the submitted token does not match the user's
	 * token, this function will return false and display an error.
	 * If the token matches, or the user is not logged in, it will return true.
	 */
	public static function verifyToken()
	{
		if (Session::get('token') === false)
			return true;
		if (isset($_REQUEST['token']) && Session::get('token') === $_REQUEST['token'])
			return true;
		Message::addError('token');
		return false;
	}

	/**
	 * Simple markup "rendering":
	 * *word* is bold
	 * /word/ is italics
	 * _word_ is underlined
	 * \n is line break
	 */
	public static function markup($string)
	{
		$string = htmlspecialchars($string);
		$string = preg_replace('#(^|[\n \-_/\.])\*(.+?)\*($|[ \-_/\.\!\?,:])#is', '$1<b>$2</b>$3', $string);
		$string = preg_replace('#(^|[\n \-\*/\.])_(.+?)_($|[ \-\*/\.\!\?,:])#is', '$1<u>$2</u>$3', $string);
		$string = preg_replace('#(^|[\n \-_\*\.])/(.+?)/($|[ \-_\*\.\!\?,:])#is', '$1<i>$2</i>$3', $string);
		return nl2br($string);
	}

	/**
	 * Common initialization for download and downloadToFile
	 * Return file handle to header file
	 */
	private static function initCurl($url, $timeout, &$head)
	{
		$ch = curl_init();
		if ($ch === false)
			Util::traceError('Could not initialize cURL');
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, ceil($timeout / 2));
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 6);
		$tmpfile = '/tmp/' . mt_rand() . '-' . time();
		$head = fopen($tmpfile, 'w+b');
		if ($head === false)
			Util::traceError("Could not open temporary head file $tmpfile for writing.");
		curl_setopt($ch, CURLOPT_WRITEHEADER, $head);
		return $ch;
	}

	/**
	 * Read 10kb from the given file handle, seek to 0 first,
	 * close the file after reading. Returns data read
	 */
	private static function getContents($fh)
	{
		fseek($fh, 0, SEEK_SET);
		$data = fread($fh, 10000);
		fclose($fh);
		return $data;
	}

	/**
	 * Download file, obey given timeout in seconds
	 * Return data on success, false on failure
	 */
	public static function download($url, $timeout, &$code)
	{
		$ch = self::initCurl($url, $timeout, $head);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$data = curl_exec($ch);
		$head = self::getContents($head);
		if (preg_match('#^HTTP/\d+\.\d+ (\d+) #', $head, $out)) {
			$code = (int) $out[1];
		} else {
			$code = 999;
		}
		curl_close($ch);
		return $data;
	}

	/**
	 * Download file, obey given timeout in seconds
	 * Return true on success, false on failure
	 */
	public static function downloadToFile($target, $url, $timeout, &$code)
	{
		$fh = fopen($target, 'wb');
		if ($fh === false)
			Util::traceError("Could not open $target for writing.");
		$ch = self::initCurl($url, $timeout, $head);
		curl_setopt($ch, CURLOPT_FILE, $fh);
		$res = curl_exec($ch);
		$head = self::getContents($head);
		curl_close($ch);
		fclose($fh);
		if ($res === false) {
			@unlink($target);
			return false;
		}
		if (preg_match('#^HTTP/\d+\.\d+ (\d+) #', $head, $out)) {
			$code = (int) $out[1];
		} else {
			$code = '999 ' . curl_error($ch);
		}
		return true;
	}

	/**
	 * Convert given number to human readable file size string.
	 * Will append Bytes, KiB, etc. depending on magnitude of number.
	 * 
	 * @param type $bytes numeric value of the filesize to make readable
	 * @param type $decimals number of decimals to show, -1 for automatic
	 * @return type human readable string representing the given filesize
	 */
	public static function readableFileSize($bytes, $decimals = -1)
	{
		static $sz = array('Byte', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
		$factor = floor((strlen($bytes) - 1) / 3);
		if ($factor == 0) {
			$decimals = 0;
		} elseif ($decimals === -1) {
			$decimals = 2 - floor((strlen($bytes) - 1) % 3);
		}
		return sprintf("%.{$decimals}f ", $bytes / pow(1024, $factor)) . $sz[$factor];
	}

	public static function sanitizeFilename($name)
	{
		return preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $name);
	}

	/**
	 * Create human readable error description from a $_FILES[<..>]['error'] code
	 * 
	 * @param int $code the code to turn into an error description
	 * @return string the error description
	 */
	public static function uploadErrorString($code)
	{
		switch ($code) {
			case UPLOAD_ERR_INI_SIZE:
				$message = "The uploaded file exceeds the upload_max_filesize directive in php.ini";
				break;
			case UPLOAD_ERR_FORM_SIZE:
				$message = "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form";
				break;
			case UPLOAD_ERR_PARTIAL:
				$message = "The uploaded file was only partially uploaded";
				break;
			case UPLOAD_ERR_NO_FILE:
				$message = "No file was uploaded";
				break;
			case UPLOAD_ERR_NO_TMP_DIR:
				$message = "Missing a temporary folder";
				break;
			case UPLOAD_ERR_CANT_WRITE:
				$message = "Failed to write file to disk";
				break;
			case UPLOAD_ERR_EXTENSION:
				$message = "File upload stopped by extension";
				break;

			default:
				$message = "Unknown upload error";
				break;
		}
		return $message;
	}

	/**
	 * Is given string a public ipv4 address?
	 *
	 * @param string $ip_addr input to check
	 * @return boolean true iff $ip_addr is a valid public ipv4 address
	 */
	public static function isPublicIpv4($ip_addr)
	{
		if (!preg_match("/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/", $ip_addr))
			return false;

		$parts = explode(".", $ip_addr);
		foreach ($parts as $part) {
			if (!is_numeric($part) || $part > 255 || $part < 0)
				return false;
		}

		if ($parts[0] == 0 || $parts[0] == 10 || $parts[0] == 127 || ($parts[0] > 223 && $parts[0] < 240))
			return false;
		if (($parts[0] == 192 && $parts[1] == 168) || ($parts[0] == 169 && $parts[1] == 254))
			return false;
		if ($parts[0] == 172 && $parts[1] > 15 && $parts[1] < 32)
			return false;

		return true;
	}

}
