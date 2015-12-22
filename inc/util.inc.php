<?php

class Util
{
	private static $redirectParams = array();

	/**
	 * Displays an error message and stops script execution.
	 * If CONFIG_DEBUG is true, it will also dump a stack trace
	 * and all globally defined variables.
	 * (As this might reveal sensistive data you should never enable it in production)
	 */
	public static function traceError($message)
	{
		if (defined('API')) {
			error_log('API ERROR: ' . $message);
		}
		Header('HTTP/1.1 500 Internal Server Error');
		Header('Content-Type: text/plain; charset=utf-8');
		echo "--------------------\nFlagrant system error:\n$message\n--------------------\n\n";
		if (defined('CONFIG_DEBUG') && CONFIG_DEBUG) {
			debug_print_backtrace();
			echo "\n\nSome variables for your entertainment:\n";
			print_r($GLOBALS);
		} else {
			echo <<<SADFACE

________________________¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶________
____________________¶¶¶___________________¶¶¶¶_____
________________¶¶¶_________________________¶¶¶¶___
______________¶¶______________________________¶¶¶__
___________¶¶¶_________________________________¶¶¶_
_________¶¶_____________________________________¶¶¶
________¶¶_________¶¶¶¶¶___________¶¶¶¶¶_________¶¶
______¶¶__________¶¶¶¶¶¶__________¶¶¶¶¶¶_________¶¶
_____¶¶___________¶¶¶¶____________¶¶¶¶___________¶¶
____¶¶___________________________________________¶¶
___¶¶___________________________________________¶¶_
__¶¶____________________¶¶¶¶____________________¶¶_
_¶¶_______________¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶______________¶¶__
_¶¶____________¶¶¶¶___________¶¶¶¶¶___________¶¶___
¶¶¶_________¶¶¶__________________¶¶__________¶¶____
¶¶_________¶______________________¶¶________¶¶_____
¶¶¶______¶________________________¶¶_______¶¶______
¶¶¶_____¶_________________________¶¶_____¶¶________
_¶¶¶___________________________________¶¶__________
__¶¶¶________________________________¶¶____________
___¶¶¶____________________________¶¶_______________
____¶¶¶¶______________________¶¶¶__________________
_______¶¶¶¶¶_____________¶¶¶¶¶_____________________
SADFACE;
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
			$location = preg_replace('/(&|\?)message\[\]\=[^&]*/', '\1', $_SERVER['REQUEST_URI']);
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
		if (!empty(self::$redirectParams)) {
			if (strpos($location, '?') === false) {
				$location .= '?' . implode('&', self::$redirectParams);
			} else {
				$location .= '&' . implode('&', self::$redirectParams);
			}
		}
		Header('Location: ' . $location);
		exit(0);
	}
	
	public static function addRedirectParam($key, $value)
	{
		self::$redirectParams[] = $key .= '=' . urlencode($value);
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

	public static function safePath($path, $prefix = '')
	{
		if (empty($path))
			return false;
		$path = trim($path);
		if ($path{0} == '/' || preg_match('/[\x00-\x19\?\*]/', $path))
			return false;
		if (strpos($path, '..') !== false)
			return false;
		if (substr($path, 0, 2) !== './')
			$path = "./$path";
		if (empty($prefix))
			return $path;
		if (substr($prefix, 0, 2) !== './')
			$prefix = "./$prefix";
		if (substr($path, 0, strlen($prefix)) !== $prefix)
			return false;
		return $path;
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

	/**
	 * Check whether $arrax contains all keys given in $keyList
	 * @param array $array An array
	 * @param array $keyList A list of strings which must all be valid keys in $array
	 * @return boolean
	 */
	public static function hasAllKeys($array, $keyList)
	{
		if (!is_array($array))
			return false;
		foreach ($keyList as $key) {
			if (!isset($array[$key]))
				return false;
		}
		return true;
	}
	
	/**
	 * Send a file to user for download.
	 *
	 * @param type $file path of local file
	 * @param type $name name of file to send to user agent
	 * @param type $delete delete the file when done?
	 * @return boolean false: file could not be opened.
	 *		true: error while reading the file
	 *		- on success, the function does not return
	 */
	public static function sendFile($file, $name, $delete = false)
	{
		while ((@ob_get_level()) > 0)
			@ob_end_clean();
		$fh = @fopen($file, 'rb');
		if ($fh === false) {
			Message::addError('error-read', $file);
			return false;
		}
		Header('Content-Type: application/octet-stream', true);
		Header('Content-Disposition: attachment; filename=' . str_replace(array(' ', '=', ',', '/', '\\', ':', '?'), '_', iconv('UTF-8', 'ASCII//TRANSLIT', $name)));
		Header('Content-Length: ' . @filesize($file));
		while (!feof($fh)) {
			$data = fread($fh, 16000);
			if ($data === false) {
				echo "\r\n\nDOWNLOAD INTERRUPTED!\n";
				if ($delete)
					@unlink($file);
				return true;
			}
			echo $data;
			@ob_flush();
			@flush();
		}
		@fclose($fh);
		if ($delete)
			@unlink($file);
		exit(0);
	}
	
	public static function normalizeDn($dn) {
		return preg_replace('/[,;]\s*/', ',', $dn);
	}

}
