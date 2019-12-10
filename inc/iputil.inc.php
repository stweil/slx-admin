<?php

class IpUtil
{

	public static function rangeToCidr($start, $end)
	{
		$value = (int)$start ^ (int)$end;
		if (!self::isAllOnes($value))
			return 'NOT SUBNET: ' . long2ip($start) . '-' . long2ip($end);
		$ones = self::countOnes($value);
		return long2ip($start) . '/' . (32 - $ones);
	}

	public static function isValidSubnetRange($start, $end)
	{
		return self::isAllOnes((int)$start ^ (int)$end);
	}

	/**
	 * Return number of one bits required to represent
	 * this number. Assumes given number is 2^n - 1.
	 */
	private static function countOnes($value)
	{
		// This is log(value) / log(2)
		// It should actually be $value + 1, but floating point errors
		// start to happen either way at higher values, so with
		// the round() thrown in, it doesn't matter...
		return round(log($value) / 0.69314718055995);
	}

	/**
	 * Is the given number just ones if converted to
	 * binary (ignoring leading zeros)?
	 */
	private static function isAllOnes($value)
	{
		return ($value & ($value + 1)) === 0;
	}

	/**
	 * Parse network range in CIDR notion, return
	 * ['start' => (int), 'end' => (int)] representing
	 * the according start and end addresses as integer
	 * values. Returns false on malformed input.
	 * @param string $cidr 192.168.101/24, 1.2.3.4/16, ...
	 * @return array|false start and end address, false on error
	 */
	public static function parseCidr($cidr)
	{
		$parts = explode('/', $cidr);
		if (count($parts) !== 2)
			return false;
		$ip = $parts[0];
		$bits = $parts[1];
		if (!is_numeric($bits) || $bits < 0 || $bits > 32)
			return false;
		$dots = substr_count($ip, '.');
		if ($dots < 3) {
			$ip .= str_repeat('.0', 3 - $dots);
		}
		$ip = ip2long($ip);
		if ($ip === false)
			return false;
		$bits = pow(2, 32 - $bits) - 1;
		if (PHP_INT_SIZE === 4)
			return ['start' => sprintf('%u', $ip & ~$bits), 'end' => sprintf('%u', $ip | $bits)];
		return ['start' => $ip & ~$bits, 'end' => $ip | $bits];
	}

}
