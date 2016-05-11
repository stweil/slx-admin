<?php

class Message
{
	private static $list = array();
	private static $alreadyDisplayed = array();
	private static $flushed = false;

	/**
	 * Add error message to page. If messages have not been flushed
	 * yet, it will be added to the queue, otherwise it will be added
	 * in place during rendering.
	 */
	public static function addError($id)
	{
		self::add('error', $id, func_get_args());
	}

	public static function addWarning($id)
	{
		self::add('warning', $id, func_get_args());
	}

	public static function addInfo($id)
	{
		self::add('info', $id, func_get_args());
	}

	public static function addSuccess($id)
	{
		self::add('success', $id, func_get_args());
	}

	/**
	 * Internal function that adds a message. Used by
	 * addError/Success/Info/... above.
	 */
	private static function add($type, $id, $params)
	{
		if (strstr($id, '.') === false) {
			$id = Page::getModule()->getIdentifier() . '.' . $id;
		}
		self::$list[] = array(
			'type'   => $type,
			'id'     => $id,
			'params' => array_slice($params, 1)
		);
		if (self::$flushed) self::renderList();
	}

	/**
	 * Render all currently queued messages, flushing the queue.
	 * After calling this, any further calls to add* will be rendered in
	 * place in the current page output.
	 */
	public static function renderList()
	{
		self::$flushed = true;
		if (empty(self::$list))
			return;
		// Ajax
		if (AJAX) {
			foreach (self::$list as $item) {
				$message = Dictionary::getMessage($item['id']);
				foreach ($item['params'] as $index => $text) {
					$message = str_replace('{{' . $index . '}}', '<b>' . htmlspecialchars($text) . '</b>', $message);
				}
				echo Render::parse('messagebox-' . $item['type'], array('message' => $message), 'main');
			}
			self::$list = array();
			return;
		}
		// Non-Ajax
		foreach (self::$list as $item) {
			$message = Dictionary::getMessage($item['id']);
			foreach ($item['params'] as $index => $text) {
				$message = str_replace('{{' . $index . '}}', '<b>' . htmlspecialchars($text) . '</b>', $message);
			}
			Render::addTemplate('messagebox-' . $item['type'], array('message' => $message), 'main');
			self::$alreadyDisplayed[] = $item;
		}
		self::$list = array();
	}

	/**
	 * Get all queued messages, flushing the queue.
	 * Useful in api/ajax mode.
	 */
	public static function asString()
	{
		$return = '';
		foreach (self::$list as $item) {
			$message = Dictionary::getMessage($item['id']);
			foreach ($item['params'] as $index => $text) {
				$message = str_replace('{{' . $index . '}}', $text, $message);
			}
			$return .= '[' . $item['type'] . ']: ' . $message . "\n";
			self::$alreadyDisplayed[] = $item;
		}
		self::$list = array();
		return $return;
	}

	/**
	 * Deserialize any messages from the current HTTP request and
	 * place them in the message queue.
	 */
	public static function fromRequest()
	{
		$messages = is_array($_REQUEST['message']) ? $_REQUEST['message'] : array($_REQUEST['message']);
		foreach ($messages as $message) {
			$data = explode('|', $message);
			if (count($data) < 2 || !preg_match('/^(error|warning|info|success)$/', $data[0])) continue;
			self::add($data[0], $data[1], array_slice($data, 1));
		}
	}

	/**
	 * Turn the current message queue into a serialized version,
	 * suitable for appending to a GET or POST request
	 */
	public static function toRequest()
	{
		$parts = array();
		foreach (array_merge(self::$list, self::$alreadyDisplayed) as $item) {
			$str = 'message[]=' . urlencode($item['type'] . '|' .$item['id']);
			if (!empty($item['params'])) {
				$str .= '|' . urlencode(implode('|', $item['params']));
			}
			$parts[] = $str;
		}
		return implode('&', $parts);
	}

}

