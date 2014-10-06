<?php

class Permission
{
	private static $permissions = array(
		'superadmin' => 1,
		'baseconfig_global' => 2,
		'baseconfig_local' => 4,
	);

	public static function get($permission)
	{
		if (!isset(self::$permissions[$permission])) Util::traceError('Invalid permission: ' . $permission);
		return self::$permissions[$permission];
	}

}

