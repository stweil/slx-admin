<?php

// TODO: Move to extra file
$error_text = array(
	'loginfail'      => 'Benutzername oder Kennwort falsch',
	'token'          => 'Ungültiges Token. CSRF Angriff?',
);

class Message
{
	private static $list = array();

	public static function addError($id)
	{
		self::$list[] = array(
			'type' => 'error',
			'id'    => $id
		);
	}

	public static function addWarning($id)
	{
		self::$list[] = array(
			'type' => 'warning',
			'id'    => $id
		);
	}

	public static function addInfo($id)
	{
		self::$list[] = array(
			'type' => 'info',
			'id'    => $id
		);
	}

	public static function addSuccess($id)
	{
		self::$list[] = array(
			'type' => 'success',
			'id'    => $id
		);
	}

	public static function renderList()
	{
		global $error_text;
		foreach (self::$list as $item) {
			Render::addTemplate('messagebox-' . $item['type'], array('message' => $error_text[$item['id']]));
		}
	}

}

