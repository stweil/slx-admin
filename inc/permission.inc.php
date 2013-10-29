<?php

class Permission
{
	private static $permissions = false;

	public static function get($permission)
	{
		self::init();
		if (!isset(self::$permissions[$permission])) Util::traceError('Invalid permission: ' . $permission);
		return self::$permissions[$permission];
	}

	private static function init()
	{
		if (self::$permissions !== false) return;
		self::$permissions = array();
		$res = Database::simpleQuery('SELECT mask, identifier FROM permission');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			self::$permissions[$row['identifier']] = $row['mask'];
		}
	}

}

