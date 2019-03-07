<?php

class Dnbd3Rpc {

	const QUERY_UNREACHABLE = 1;
	const QUERY_NOT_200 = 2;
	const QUERY_NOT_JSON = 3;

	/**
	 * Query given DNBD3 server for status information.
	 *
	 * @param string $server server address
	 * @param int $port server port
	 * @param bool $stats include general stats
	 * @param bool $clients include client list
	 * @param bool $images include image list
	 * @param bool $diskSpace include disk space stats
	 * @param bool $config get config
	 * @param bool $altservers list of alt servers with status
	 * @return int|array the queried data as an array, or false on error
	 */
	public static function query($server, $stats, $clients, $images, $diskSpace = false, $config = false, $altservers = false)
	{
		// Special case - local server
		if ($server === '<self>') {
			$server = '127.0.0.1:5003';
		} elseif (($out = Dnbd3Util::matchAddress($server))) {
			if (isset($out['v4'])) {
				$server = $out['v4'];
			} else {
				$server = '[' . $out['v6'] . ']';
			}
			if (isset($out['port'])) {
				$server .= $out['port'];
			} else {
				$server .= ':5003';
			}
		}
		$url = 'http://' . $server . '/query?';
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
		if ($config) {
			$url .= 'q=config&';
		}
		if ($altservers) {
			$url .= 'q=altservers&';
		}
		$str = Download::asString($url, 3, $code);
		if ($str === false)
			return self::QUERY_UNREACHABLE;
		if ($code !== 200)
			return self::QUERY_NOT_200;
		$ret = json_decode($str, true);
		if (!is_array($ret))
			return self::QUERY_NOT_JSON;
		return $ret;
	}

}
