<?php

class ConfigHolder
{
	private static $config = [];

	private static $context = '';

	private static $postHooks = [];

	public static function setContext($id, $resolver = false)
	{
		$tmp = ['id' => $id, 'resolver' => $resolver];
		self::$context =& $tmp;
	}

	/**
	 * @param string $key config variable name
	 * @param false|string|array $value false to unset, string value, or array with keys value and displayvalue
	 * @param int $prio priority of this value, in case the same key gets set multiple times
	 */
	public static function add($key, $value, $prio = 0)
	{
		if (!isset(self::$config[$key])) {
			self::$config[$key] = [];
		}
		$new = [
			'prio' => $prio,
			'context' => &self::$context,
		];
		if (is_array($value)) {
			$new['value'] = $value['value'];
			$new['displayvalue'] = $value['displayvalue'];
		} else {
			$new['value'] = $value;
		}
		if (empty(self::$config[$key]) || self::$config[$key][0]['prio'] > $prio) {
			// Existing is higher, append new one
			array_push(self::$config[$key], $new);
		} else {
			// New one has highest prio or matches existing, put in front
			array_unshift(self::$config[$key], $new);
		}
	}

	public static function get($key)
	{
		if (!isset(self::$config[$key]))
			return false;
		return self::$config[$key][0]['value'];
	}

	/**
	 * @param callable $func
	 */
	public static function addPostHook($func)
	{
		self::$postHooks[] = array('context' => &self::$context, 'function' => $func);
	}

	public static function applyPostHooks()
	{
		foreach (self::$postHooks as $hook) {
			$newContext = $hook['context'];
			$newContext['post'] = true;
			self::$context =& $newContext;
			$hook['function']();
		}
		self::$postHooks = [];
	}

	public static function getRecursiveConfig($prettyPrint = true)
	{
		$ret = [];
		foreach (self::$config as $key => $list) {
			$last = false;
			foreach ($list as $entry) {
				if ($last !== false && $last['context']['id'] === '<global>'
						&& $entry['context']['id'] === '<default>' && $last['value'] === $entry['value'])
					continue;
				$cb =& $entry['context']['resolver'];
				$valueKey = 'value';
				if ($prettyPrint && is_callable($cb)) {
					$data = $cb($entry['context']['id']);
					$name = $data['name'];
					if (isset($data['locationid']) && isset($entry['displayvalue'])
							&& User::hasPermission('.baseconfig.view', $data['locationid'])) {
						$valueKey = 'displayvalue';
					}
				} else {
					$name = $entry['context']['id'];
				}
				$ret[$key][] = ['name' => $name, 'value' => $entry[$valueKey]];
				$last = $entry;
			}
		}
		return $ret;
	}

	public static function outputConfig()
	{
		foreach (self::$config as $key => $list) {
			echo str_pad('# ' . $key . ' ', 35, '#', STR_PAD_BOTH), "\n";
			foreach ($list as $pos => $item) {
				$ctx = $item['context']['id'];
				if (isset($item['context']['post'])) {
					$ctx .= '[post-hook]';
				}
				$ctx .= ' :: ' . $item['prio'];
				if ($pos != 0 || $item['value'] === false) {
					echo '# (', $ctx, ')';
					if ($pos == 0) {
						echo " <disabled this setting>\n";
					} else {
						echo " <overridden>\n";
					}
				} else {
					echo $key, "='", self::escape($item['value']), "'\n";
					echo '# (', $ctx, ") <active>\n";
				}
			}
		}
	}

	/**
	 * Escape given string so it is a valid string in sh that can be surrounded
	 * by single quotes ('). This basically turns _'_ into _'"'"'_
	 *
	 * @param string $string input
	 * @return string escaped sh string
	 */
	private static function escape($string)
	{
		return str_replace("'", "'\"'\"'", $string);
	}

}
