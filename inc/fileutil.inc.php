<?php

class FileUtil
{
	
	/**
	 * Return contents of given file as string, but only read up to maxBytes bytes.
	 *
	 * @param string $file file to read
	 * @param int $maxBytes maximum length to read
	 * @return boolean|string data, false on error
	 */
	public static function readFile($file, $maxBytes = 1000)
	{
		$fh = @fopen($file, 'rb');
		if ($fh === false)
			return false;
		$data = fread($fh, $maxBytes);
		fclose($fh);
		return $data;
	}
	
	/**
	 * Read a file of key=value lines to assoc array.
	 *
	 * @param string $file Filename
	 * @return boolean|array assoc array, false on error
	 */
	public static function fileToArray($file)
	{
		$data = self::readFile($file, 2000);
		if ($data === false)
			return false;
		$data = explode("\n", str_replace("\r", "\n", $data));
		$ret = array();
		foreach ($data as $line) {
			if (preg_match('/^(\w+)\s*=\s*(.*?)\s*$/', $line, $out)) {
				$ret[$out[1]] = $out[2];
			}
		}
		return $ret;
	}
	
	/**
	 * Write given associative array to file as key=value pairs.
	 * 
	 * @param string $file Filename
	 * @param array $array Associative array to write
	 * @return boolean success of operation
	 */
	public static function arrayToFile($file, $array)
	{
		$fh = fopen($file, 'wb');
		if ($fh === false)
			return false;
		foreach ($array as $key => $value) {
			if (false === fwrite($fh, $key . ' = ' . preg_replace('/[\x00-\x1F]/s', '', (string)$value) . "\n")) {
				fclose($fh);
				return false;
			}
		}
		fclose($fh);
		return true;
	}

}