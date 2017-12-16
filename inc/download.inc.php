<?php

class Download
{

	private static $curlHandle = false;

	/**
	 * Common initialization for download and downloadToFile
	 * Return file handle to header file
	 */
	private static function initCurl($url, $timeout, &$head)
	{
		if (self::$curlHandle === false) {
			self::$curlHandle = curl_init();
			if (self::$curlHandle === false) {
				Util::traceError('Could not initialize cURL');
			}
			curl_setopt(self::$curlHandle, CURLOPT_CONNECTTIMEOUT, ceil($timeout / 2));
			curl_setopt(self::$curlHandle, CURLOPT_TIMEOUT, $timeout);
			curl_setopt(self::$curlHandle, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt(self::$curlHandle, CURLOPT_AUTOREFERER, true);
			curl_setopt(self::$curlHandle, CURLOPT_BINARYTRANSFER, true);
			curl_setopt(self::$curlHandle, CURLOPT_MAXREDIRS, 6);
		}

		curl_setopt(self::$curlHandle, CURLOPT_URL, $url);

		$tmpfile = tempnam('/tmp/', 'bwlp-');
		$head = fopen($tmpfile, 'w+b');
		unlink($tmpfile);
		if ($head === false)
			Util::traceError("Could not open temporary head file $tmpfile for writing.");
		curl_setopt(self::$curlHandle, CURLOPT_WRITEHEADER, $head);
		return self::$curlHandle;
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
	public static function asString($url, $timeout, &$code)
	{
		$ch = self::initCurl($url, $timeout, $head);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$data = curl_exec($ch);
		$head = self::getContents($head);
		if (preg_match_all('#^HTTP/\d+\.\d+ (\d+) #m', $head, $out)) {
			$code = (int) array_pop($out[1]);
		} else {
			$code = 999;
		}
		return $data;
	}

	/**
	 * POST-Download file, obey given timeout in seconds
	 * Return data on success, false on failure
	 * @param string $url URL to fetch
	 * @param array|false $params POST params to set in body, list of key-value-pairs
	 * @param int $timeout timeout in seconds
	 * @param int $code HTTP response code, or 999 on error
	 */
	public static function asStringPost($url, $params, $timeout, &$code)
	{
		$string = '';
		if (is_array($params)) {
			foreach ($params as $k => $v) {
				if (!empty($string)) {
					$string .= '&';
				}
				$string .= $k . '=' . urlencode($v);
			}
		}
		$ch = self::initCurl($url, $timeout, $head);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $string);
		$data = curl_exec($ch);
		$head = self::getContents($head);
		if (preg_match_all('#^HTTP/\d+\.\d+ (\d+) #m', $head, $out)) {
			$code = (int) array_pop($out[1]);
		} else {
			$code = 999;
		}
		return $data;
	}

	/**
	 * Download a file from a URL to file.
	 *
	 * @param string $target destination path to download file to
	 * @param string $url URL of file to download
	 * @param int $timeout timeout in seconds
	 * @param int $code HTTP status code passed out by reference
	 * @return boolean
	 */
	public static function toFile($target, $url, $timeout, &$code)
	{
		$fh = fopen($target, 'wb');
		if ($fh === false)
			Util::traceError("Could not open $target for writing.");
		$ch = self::initCurl($url, $timeout, $head);
		curl_setopt($ch, CURLOPT_FILE, $fh);
		$res = curl_exec($ch);
		$head = self::getContents($head);
		fclose($fh);
		if ($res === false) {
			@unlink($target);
			return false;
		}
		if (preg_match_all('#^HTTP/\d+\.\d+ (\d+) #m', $head, $out)) {
			$code = (int) array_pop($out[1]);
		} else {
			$code = '999 ' . curl_error($ch);
		}
		return true;
	}

}
