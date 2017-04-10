<?php

class PermissionUtil
{
	public static function userHasPermission($userid, $permissionid, $locationid) {
		$locations = array();
		if (!is_null($locationid)) {
			$res = Database::simpleQuery("SELECT locationid, parentlocationid FROM location");
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$locations[$row["locationid"]] = $row["parentlocationid"];
			}
			if (!array_key_exists($locationid, $locations)) return false;
		}

		$res = Database::simpleQuery("SELECT role_x_permission.permissionid as 'permissionid',
													role_x_location.locid as 'locationid'
												FROM user_x_role
												INNER JOIN role_x_permission ON user_x_role.roleid = role_x_permission.roleid
												LEFT JOIN role_x_location ON role_x_permission.roleid = role_x_location.roleid
												WHERE user_x_role.userid = :userid", array("userid" => $userid));

		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$userPermission = trim($row["permissionid"], "*");
			if (substr($permissionid, 0, strlen($userPermission)) === $userPermission) {
				if (is_null($locationid) || $locationid == $row["locationid"]) {
					return true;
				} else {
					$parentlocid = $locationid;
					while ($parentlocid != 0) {
						$parentlocid  = $locations[$parentlocid];
						if ($parentlocid == $row["locationid"]) return true;
					}
				}
			}
		}
		return false;
	}


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