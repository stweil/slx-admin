<?php

class ArrayUtil
{

	/**
	 * Take an array of arrays, take given key from each sub-array and return
	 * new array with just those corresponding values.
	 * @param array $list
	 * @param string $key
	 * @return array
	 */
	public static function flattenByKey($list, $key)
	{
		$ret = [];
		foreach ($list as $item) {
			if (array_key_exists($key, $item)) {
				$ret[] = $item[$key];
			}
		}
		return $ret;
	}

}