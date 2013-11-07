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
			echo "\n\n";
			print_r($GLOBALS);
		}
		exit(0);
	}

	/**
	 * Redirects the user via a '302 Moved' header.
	 * An active session will be saved, any messages that haven't
	 * been displayed yet will be appended to the redirect.
	 */
	public static function redirect($location)
	{
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
		if (Session::get('token') === false) return true;
		if (isset($_REQUEST['token']) && Session::get('token') === $_REQUEST['token']) return true;
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
		$string = preg_replace('#(^|[\n \-_/\.])\*(.+?)\*($|[ \-_/\.\!\?,])#is', '$1<b>$2</b>$3', $string);
		$string = preg_replace('#(^|[\n \-\*/\.])_(.+?)_($|[ \-\*/\.\!\?,])#is', '$1<u>$2</u>$3', $string);
		$string = preg_replace('#(^|[\n \-_\*\.])/(.+?)/($|[ \-_\*\.\!\?,])#is', '$1<i>$2</i>$3', $string);
		return nl2br($string);
	}

	/**
	 * Common initialization for download and downloadToFile
	 * Return file handle to header file
	 */
	private static function initCurl($url, $timeout, &$head)
	{
		$ch = curl_init();
		if ($ch === false) Util::traceError('Could not initialize cURL');
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, ceil($timeout / 2));
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 6);
		$tmpfile = '/tmp/' . mt_rand() . '-' . time();
		$head = fopen($tmpfile, 'w+b');
		if ($head === false) Util::traceError("Could not open temporary head file $tmpfile for writing.");
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
		return fread($fh, 10000);
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
			$code = (int)$out[1];
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
		if ($fh === false) Util::traceError("Could not open $target for writing.");
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
			$code = (int)$out[1];
		} else {
			$code = 999;
		}
		return true;
	}

}

