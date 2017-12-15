<?php

class PermissionUtil
{
	public static function userHasPermission($userid, $permissionid, $locationid) {
		$locations = array();
		if (!is_null($locationid)) {
			$locations = Location::getLocationRootChain($locationid);
			if (count($locations) == 0) return false;
			else $locations[] = 0;
		}

		$res = Database::simpleQuery("SELECT role_x_permission.permissionid as 'permissionid',
													role_x_location.locationid as 'locationid'
												FROM user_x_role
												INNER JOIN role_x_permission ON user_x_role.roleid = role_x_permission.roleid
												LEFT JOIN role_x_location ON role_x_permission.roleid = role_x_location.roleid
												WHERE user_x_role.userid = :userid", array("userid" => $userid));

		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$userPermission = trim($row["permissionid"], "*");
			if (substr($permissionid, 0, strlen($userPermission)) === $userPermission
					&& (is_null($locationid) || in_array($row["locationid"], $locations))) {
				return true;
			}
		}
		return false;
	}

	public static function getAllowedLocations($userid, $permissionid) {

		$res = Database::simpleQuery("SELECT permissionid, COALESCE(locationid, 0) AS locationid FROM user_x_role
												INNER JOIN role_x_permission ON user_x_role.roleid = role_x_permission.roleid
												INNER JOIN role_x_location ON role_x_permission.roleid = role_x_location.roleid
												WHERE user_x_role.userid = :userid", array("userid" => $userid));

		$allowedLocations = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$userPermission = trim($row["permissionid"], "*");
			if (!is_null($row["locationid"]) && substr($permissionid, 0, strlen($userPermission)) === $userPermission) {
				$allowedLocations[$row["locationid"]] = 1;
			}
		}
		$allowedLocations = array_keys($allowedLocations);
		$locations = Location::getTree();
		if (in_array("0", $allowedLocations)) {
			$allowedLocations = array_map("intval", Location::extractIds($locations));
		} else {
			$allowedLocations = self::getSublocations($locations, $allowedLocations);
		}
		return $allowedLocations;
	}

	public static function getSublocations($tree, $locations) {
		$result = array_flip($locations);
		foreach ($tree as $location) {
			if (array_key_exists("children", $location)) {
				if (in_array($location["locationid"], $locations)) {
					$result += array_flip(Location::extractIds($location["children"]));
				} else {
					$result += array_flip(self::getSublocations($location["children"], $locations));
				}
			}
		}
		return array_keys($result);
	}

	public static function getPermissions()
	{
		$permissions = array();
		foreach (glob("modules/*/permissions/permissions.json", GLOB_NOSORT) as $file) {
			$data = json_decode(file_get_contents($file), true);
			if (!is_array($data))
				continue;
			preg_match('#^modules/([^/]+)/#', $file, $out);
			foreach( $data as $p ) {
				$description = Dictionary::translateFileModule($out[1], "permissions", $p);
				$permissions = self::putInPermissionTree($out[1].".".$p, $description, $permissions);
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