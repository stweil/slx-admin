<?php

class PermissionUtil
{
	public static function getPermissions()
	{
		$permissions = array();
		foreach (glob("modules/*/permissions/permissions.json", GLOB_NOSORT) as $file) {
			$data = json_decode(file_get_contents($file), true);
			if (!is_array($data))
				continue;
			preg_match('#^modules/([^/]+)/#', $file, $out);
			$newData = array();
			foreach( $data as $k => $v ) {
				$newData[] = $v;
				$permissions = self::putInPermissionTree($out[1].".".$k, $v, $permissions);
			}
		}
		ksort($permissions);
		global $MENU_CAT_OVERRIDE;
		$sortingOrder = $MENU_CAT_OVERRIDE;
		foreach ($permissions as $module => $v) $sortingOrder[Module::get($module)->getCategory()][] = $module;
		$permissions = array_replace(array_flip(call_user_func_array('array_merge', $sortingOrder)), $permissions);
		foreach ($permissions as $module => $v) if (is_int($v)) unset($permissions[$module]);


		return $permissions;
	}

	private static function putInPermissionTree($permission, $description, $tree)
	{
		$subPermissions = explode('.', $permission);
		$original =& $tree;
		foreach ($subPermissions as $subPermission) {
			if ($subPermission) {
				if (!array_key_exists($subPermission, $tree)) {
					$tree[$subPermission] = array();
				}
				$tree =& $tree[$subPermission];
			}
		}
		$tree = $description;
		return $original;
	}
}