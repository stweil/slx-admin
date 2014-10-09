<?php

class Permission
{
	private static $permissions = array(
		'superadmin' => 1, // Can do everything
		'baseconfig_global' => 2, // Change configuration globally
		'baseconfig_local' => 4, // Change configuration for specifig groups/rooms
		'translation' => 8, // Can edit translations
	);

	public static function get($permission)
	{
		if (!isset(self::$permissions[$permission])) Util::traceError('Invalid permission: ' . $permission);
		return self::$permissions[$permission];
	}

}

