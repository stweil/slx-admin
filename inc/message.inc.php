<?php

// TODO: Move to extra file
$error_text = array(
	'loginfail'         => 'Benutzername oder Kennwort falsch',
	'token'             => 'Ungültiges Token. CSRF Angriff?',
	'adduser-disabled'  => 'Keine ausreichenden Rechte, um weitere Benutzer hinzuzufügen',
	'password-mismatch' => 'Passwort und Passwortbestätigung stimmen nicht überein',
	'empty-field'       => 'Ein benötigtes Feld wurde nicht ausgefüllt',
	'adduser-success'   => 'Benutzer erfolgreich hinzugefügt',
);

class Message
{
	private static $list = array();
	private static $flushed = false;

	public static function addError($id)
	{
		self::$list[] = array(
			'type' => 'error',
			'id'    => $id
		);
		if (self::$flushed) self::renderList();
	}

	public static function addWarning($id)
	{
		self::$list[] = array(
			'type' => 'warning',
			'id'    => $id
		);
		if (self::$flushed) self::renderList();
	}

	public static function addInfo($id)
	{
		self::$list[] = array(
			'type' => 'info',
			'id'    => $id
		);
		if (self::$flushed) self::renderList();
	}

	public static function addSuccess($id)
	{
		self::$list[] = array(
			'type' => 'success',
			'id'    => $id
		);
		if (self::$flushed) self::renderList();
	}

	public static function renderList()
	{
		global $error_text;
		foreach (self::$list as $item) {
			Render::addTemplate('messagebox-' . $item['type'], array('message' => $error_text[$item['id']]));
		}
		self::$list = array();
		self::$flushed = true;
	}

}

