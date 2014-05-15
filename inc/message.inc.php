<?php

// TODO: Move to extra file
global $error_text;
$error_text = array(
	'loginfail'           => 'Benutzername oder Kennwort falsch',
	'token'               => 'Ungültiges Token. CSRF Angriff?',
	'adduser-disabled'    => 'Keine ausreichenden Rechte, um weitere Benutzer hinzuzufügen',
	'password-mismatch'   => 'Passwort und Passwortbestätigung stimmen nicht überein',
	'empty-field'         => 'Ein Feld wurde nicht ausgefüllt',
	'adduser-success'     => 'Benutzer erfolgreich hinzugefügt',
	'no-permission'       => 'Keine ausreichenden Rechte, um auf diese Seite zuzugreifen',
	'settings-updated'    => 'Einstellungen wurden aktualisiert',
	'debug-mode'          => 'Der Debug-Modus ist aktiv!',
	'value-invalid'       => 'Der Wert {{1}} ist ungültig für die Option {{0}} und wurde ignoriert',
	'invalid-action'      => 'Ungültige Aktion: {{0}}',
	'remote-timeout'      => 'Konnte Ressource {{0}} nicht herunterladen ({{1}})',
	'remote-parse-failed' => 'Parsen der empfangenen Daten fehlgeschlagen ({{0}})',
	'missing-file'        => 'Es wurde keine Datei ausgewählt!',
	'invalid-file'        => 'Die Datei {{0}} existiert nicht!',
	'upload-complete'     => 'Upload von {{0}} war erfolgreich',
	'upload-failed'       => 'Upload von {{0}} schlug fehl!',
	'config-activated'    => 'Konfiguration wurde aktiviert',
	'error-write'         => 'Fehler beim Schreiben von {{0}}',
	'error-read'          => 'Fehler beim Lesen von {{0}}',
	'error-archive'       => 'Korruptes Archiv oder nicht unterstütztes Format',
	'error-rename'        => 'Konnte {{0}} nicht in {{1}} umbenennen',
	'error-nodir'         => 'Das Verzeichnis {{0}} existiert nicht.',
	'empty-archive'       => 'Das Archiv enthält keine Dateien oder Verzeichnisse',
	'error-extract'       => 'Konnte Archiv nicht nach {{0}} entpacken - {{1}}',
	'module-added'        => 'Modul erfolgreich hinzugefügt',
	'taskmanager-error'   => 'Verbindung zum Taskmanager fehlgeschlagen',
	'task-error'          => 'Ausführung fehlgeschlagen: {{0}}',
);

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
			self::$alreadyDisplayed[] = $item;
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
		foreach (array_merge(self::$list, self::$alreadyDisplayed) as $item) {
			$str = 'message[]=' . urlencode($item['type'] . '|' .$item['id']);
			if (!empty($item['params'])) {
				$str .= '|' . implode('|', $item['params']);
			}
			$parts[] = $str;
		}
		return implode('&', $parts);
	}

}

