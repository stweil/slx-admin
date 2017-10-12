<?php

class Dnbd3Rpc {

	/**
	 * Query given DNBD3 server for status information.
	 *
	 * @param string $server server address
	 * @param int $port server port
	 * @param bool $stats include general stats
	 * @param bool $clients include client list
	 * @param bool $images include image list
	 * @param bool $diskSpace include disk space stats
	 * @return false|array the queried data as an array, or false on error
	 */
	public static function query($server, $port, $stats, $clients, $images, $diskSpace)
	{
		// Special case - local server
		if ($server === '<self>') {
			$server = '127.0.0.1';
		}
		$url = 'http://' . $server . ':' . $port . '/query?';
		if ($stats) {
			$url .= 'q=stats&';
		}
		if ($clients) {
			$url .= 'q=clients&';
		}
		if ($images) {
			$url .= 'q=images&';
		}
		if ($diskSpace) {
			$url .= 'q=space&';
		}
		$str = Download::asString($url, 3, $code);
		if ($str === false || $code !== 200)
			return false;
		return json_decode($str, true);
	}

}
