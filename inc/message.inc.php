<?php

// TODO: Move to extra file
$error_text = array(
	'loginfail'         => 'Benutzername oder Kennwort falsch',
	'token'             => 'Ungültiges Token. CSRF Angriff?',
	'adduser-disabled'  => 'Keine ausreichenden Rechte, um weitere Benutzer hinzuzufügen',
	'password-mismatch' => 'Passwort und Passwortbestätigung stimmen nicht überein',
	'empty-field'       => 'Ein Feld wurde nicht ausgefüllt',
	'adduser-success'   => 'Benutzer erfolgreich hinzugefügt',
	'no-permission'     => 'Keine ausreichenden Rechte, um auf diese Seite zuzugreifen',
	'settings-updated'  => 'Einstellungen wurden aktualisiert',
	'debug-mode'        => 'Der Debug-Modus ist aktiv!',
	'value-invalid'     => 'Der Wert {{1}} ist ungültig für die Option {{0}} und wurde ignoriert',
);

class Message
{
	private static $list = array();
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
		global $error_text;
		if (!isset($error_text[$id])) Util::traceError('Invalid message id: ' . $id);
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
		global $error_text;
		foreach (self::$list as $item) {
			$message = $error_text[$item['id']];
			foreach ($item['params'] as $index => $text) {
				$message = str_replace('{{' . $index . '}}', $text, $message);
			}
			Render::addTemplate('messagebox-' . $item['type'], array('message' => $message));
		}
		self::$list = array();
		self::$flushed = true;
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
		foreach (self::$list as $item) {
			$str = 'message[]=' . urlencode($item['type'] . '|' .$item['id']);
			if (!empty($item['params'])) {
				$str .= '|' . implode('|', $item['params']);
			}
			$parts[] = $str;
		}
		return implode('&', $parts);
	}

}
