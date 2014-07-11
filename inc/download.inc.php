<?php

class Download
{

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
		$tmpfile = tempnam('/tmp/', 'bwlp-');
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
	public static function asString($url, $timeout, &$code)
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
		curl_close($ch);
		fclose($fh);
		if ($res === false) {
			@unlink($target);
			return false;
		}
		if (preg_match_all('#\bHTTP/\d+\.\d+ (\d+) #', $head, $out, PREG_SET_ORDER)) {
			$code = (int) $out[count($out)-1][1];
		} else {
			$code = '999 ' . curl_error($ch);
		}
		return true;
	}

}
