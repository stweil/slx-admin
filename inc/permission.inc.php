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


	// TODO: Doc/Refactor
	public static function addGlobalTags(&$array, $locationid, $disabled, $noneAvailDisabled = null)
	{
		if (!Module::isAvailable('permissionmanager'))
			return;
		$one = false;
		foreach ($disabled as $perm) {
			if (User::hasPermission($perm, $locationid)) {
				$one = true;
				continue;
			}
			if (strpos($perm, '.') === false) {
				$array[$perm]['disabled'] = 'disabled';
				continue;
			}
			$temp =& $array;
			foreach (explode('.', $perm) as $sub) {
				if (empty($sub))
					continue;
				$temp =& $temp[$sub];
			}
			$temp['disabled'] = 'disabled';
		}
		if (!$one && !is_null($noneAvailDisabled)) {
			$array[$noneAvailDisabled]['disabled'] = true;
		}
	}

}

