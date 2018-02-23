<?php

class PermissionUtil
{

	/**
	 * Generate all possible variants to match against, eg. $permissionid = a.b.c then we get:
	 * [ *, a.*, a.b.*, a.b.c ]
	 * In case $permissionid ends with an asterisk, also set $wildcard and $wclen, e.g.
	 * $permissionid = a.b.* --> $wildcard = a.b. and $wclen = 4
	 *
	 * @param $permission string|string[] permission to mangle
	 * @param string[] $compare all the generated variants
	 * @param string|false $wildcard if $permission is a wildcard string this returns the matching variant
	 * @param int|false $wclen if $permission is a wildcard string, this is the length of the matching variant
	 */
	private static function makeComparisonVariants($permission, &$compare, &$wildcard, &$wclen)
	{
		if (!is_array($permission)) {
			$permission = explode('.', $permission);
		}
		$partCount = count($permission);
		$compare = [];
		for ($i = 0; $i < $partCount; ++$i) {
			$compare[] = $permission[0];
		}
		for ($i = 1; $i < $partCount; ++$i) {
			$compare[$i - 1] .= '.*';
			for ($j = $i; $j < $partCount; ++$j) {
				$compare[$j] .= '.' . $permission[$i];
			}
		}
		$compare[] = '*';

		if ($permission[$partCount - 1] === '*') {
			$wildcard = substr($compare[$partCount - 1], 0, -1);
			$wclen = strlen($wildcard);
		} else {
			$wclen = $wildcard = false;
		}
	}

	/**
	 * Check if the user has the given permission (for the given location).
	 *
	 * @param string $userid userid to check
	 * @param string $permissionid permissionid to check
	 * @param int|null $locationid locationid to check or null if the location should be disregarded
	 * @return bool true if user has permission, false if not
	 */
	public static function userHasPermission($userid, $permissionid, $locationid)
	{
		$permissionid = strtolower($permissionid);
		self::validatePermission($permissionid);
		$parts = explode('.', $permissionid);
		// Special case: To prevent lockout, userid === 1 always has permissionmanager.*
		if ($parts[0] === 'permissionmanager' && User::getId() === 1)
			return true;
		// Limit query to first part of permissionid, which is always the module id
		$prefix = $parts[0] . '.%';
		if (is_null($locationid)) {
			$res = Database::simpleQuery("SELECT permissionid FROM role_x_permission
					INNER JOIN role_x_user USING (roleid)
					WHERE role_x_user.userid = :userid AND (permissionid LIKE :prefix OR permissionid LIKE '*')",
				compact('userid', 'prefix'));
		} else {
			if ($locationid === 0) {
				$locations = [0];
			} else {
				$locations = Location::getLocationRootChain($locationid);
				if (empty($locations)) { // Non-existent location, still continue as user might have global perms
					$locations = [0];
				}
			}
			$res = Database::simpleQuery("SELECT permissionid FROM role_x_permission
					INNER JOIN role_x_user USING (roleid)
					INNER JOIN role_x_location USING (roleid)
					WHERE role_x_user.userid = :userid AND (permissionid LIKE :prefix OR permissionid LIKE '*')
						AND (locationid IN (:locations) OR locationid IS NULL)",
				compact('userid', 'prefix', 'locations'));
		}
		// Quick bailout - no results
		if ($res->rowCount() === 0)
			return false;

		// Compare to database result
		self::makeComparisonVariants($parts, $compare, $wildcard, $wclen);
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (in_array($row['permissionid'], $compare, true))
				return true;
			if ($wildcard !== false && strncmp($row['permissionid'], $wildcard, $wclen) === 0)
				return true;
		}
		return false;
	}

	/**
	 * Get all locations where the user has the given permission.
	 *
	 * @param string $userid userid to check
	 * @param string $permissionid permissionid to check
	 * @return array array of locationids where the user has the given permission
	 */
	public static function getAllowedLocations($userid, $permissionid)
	{
		$permissionid = strtolower($permissionid);
		self::validatePermission($permissionid);
		$parts = explode('.', $permissionid);
		// Special case: To prevent lockout, userid === 1 always has permissionmanager.*
		if ($parts[0] === 'permissionmanager' && User::getId() === 1) {
			$allowedLocations = [true];
		} else {
			// Limit query to first part of permissionid, which is always the module id
			$prefix = $parts[0] . '.%';
			$res = Database::simpleQuery("SELECT permissionid, locationid FROM role_x_permission
						INNER JOIN role_x_user USING (roleid)
						INNER JOIN role_x_location USING (roleid)
						WHERE role_x_user.userid = :userid AND (permissionid LIKE :prefix OR permissionid LIKE '*')",
				compact('userid', 'prefix'));

			// Gather locationid from relevant rows
			self::makeComparisonVariants($parts, $compare, $wildcard, $wclen);
			$allowedLocations = array();
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				if (in_array($row['permissionid'], $compare, true)
					|| ($wildcard !== false && strncmp($row['permissionid'], $wildcard, $wclen) === 0)) {
					$allowedLocations[(int)$row['locationid']] = true;
				}
			}
		}
		$locations = Location::getTree();
		if (isset($allowedLocations[0])) {
			// Trivial case - have permission for all locations, so populate list with all valid locationds
			$allowedLocations = Location::extractIds($locations);
			$allowedLocations[] = 0; // .. plus 0 to show that we have global perms
		} else {
			// We have a specific list of locationds - add any sublocations to list
			$allowedLocations = self::getSublocations($locations, $allowedLocations);
		}
		return $allowedLocations;
	}

	/**
	 * Extend an array of locations by adding all sublocations.
	 *
	 * @param array $tree tree of all locations (structured like Location::getTree())
	 * @param array $allowedLocations the array of locationids to extend
	 * @return array extended array of locationids
	 */
	public static function getSublocations($tree, $allowedLocations)
	{
		$result = $allowedLocations;
		foreach ($tree as $location) {
			if (array_key_exists("children", $location)) {
				if (isset($allowedLocations[$location["locationid"]])) {
					$result += array_flip(Location::extractIds($location["children"]));
				} else {
					$result += array_flip(self::getSublocations($location["children"], $allowedLocations));
				}
			}
		}
		return array_keys($result);
	}

	/**
	 * If in debug mode, validate that the checked permission is actually defined
	 * in the according permissions.json and complain if that's not the case.
	 * This is supposed to catch misspelled permission checks.
	 *
	 * @param string $permissionId permission to check
	 */
	private static function validatePermission($permissionId)
	{
		if (!CONFIG_DEBUG || $permissionId === '*')
			return;
		$split = explode('.', $permissionId, 2);
		if (count($split) !== 2) {
			trigger_error('[skip:3]Cannot check malformed permission "' . $permissionId . '"', E_USER_WARNING);
			return;
		}
		if ($split[1] === '*')
			return;
		$data = json_decode(file_get_contents('modules/' . $split[0] . '/permissions/permissions.json'), true);
		if (substr($split[1], -2) === '.*') {
			$len = strlen($split[1]) - 1;
			foreach ($data as $perm => $v) {
				if (strncmp($split[1], $perm, $len) === 0)
					return;
			}
			trigger_error('[skip:3]Permission "' . $permissionId . '" does not match anything defined for module', E_USER_WARNING);
		} elseif (!is_array($data) || !array_key_exists($split[1], $data)) {
			trigger_error('[skip:3]Permission "' . $permissionId . '" not defined for module', E_USER_WARNING);
		}
	}

	/**
	 * Get all permissions of all active modules that have permissions in their permissions/permissions.json file.
	 *
	 * @return array permission tree as a multidimensional array
	 */
	public static function getPermissions()
	{
		$permissions = array();
		foreach (glob("modules/*/permissions/permissions.json", GLOB_NOSORT) as $file) {
			$data = json_decode(file_get_contents($file), true);
			if (!is_array($data))
				continue;
			preg_match('#^modules/([^/]+)/#', $file, $out);
			$moduleId = $out[1];
			if (Module::get($moduleId) === false)
				continue;
			foreach ($data as $perm => $permissionFlags) {
				$description = Dictionary::translateFileModule($moduleId, "permissions", $perm);
				self::putInPermissionTree($moduleId . "." . $perm, $permissionFlags['location-aware'], $description, $permissions);
			}
		}
		ksort($permissions);
		global $MENU_CAT_OVERRIDE;
		$sortingOrder = $MENU_CAT_OVERRIDE;
		foreach ($permissions as $module => $v) {
			$sortingOrder[Module::get($module)->getCategory()][] = $module;
		}
		$permissions = array_replace(array_flip(call_user_func_array('array_merge', $sortingOrder)), $permissions);
		foreach ($permissions as $module => $v) {
			if (is_int($v)) {
				unset($permissions[$module]);
			}
		}


		return $permissions;
	}

	/**
	 * Get all existing roles.
	 *
	 * @param int|false $userid Which user to consider, false = none
	 * @param bool $onlyMatching true = filter roles the user doesn't have
	 * @return array list of roles
	 */
	public static function getRoles($userid = false, $onlyMatching = true)
	{
		if ($userid === false) {
			return Database::queryAll('SELECT roleid, rolename FROM role ORDER BY rolename ASC');
		}
		$ret = Database::queryAll('SELECT r.roleid, r.rolename, u.userid AS hasRole FROM role r
				LEFT JOIN role_x_user u ON (r.roleid = u.roleid AND u.userid = :userid)
				GROUP BY r.roleid
				ORDER BY rolename ASC', ['userid' => $userid]);
		foreach ($ret as &$role) {
			settype($role['hasRole'], 'bool');
		}
		return $ret;
	}

	/**
	 * Place a permission into the given permission tree.
	 *
	 * @param string $permission the permission to place in the tree
	 * @param bool $locationAware whether this permissions can be restricted to specific locations only
	 * @param string $description the description of the permission
	 * @param array $tree the permission tree to modify
	 */
	private static function putInPermissionTree($permission, $locationAware, $description, &$tree)
	{
		$subPermissions = explode('.', $permission);
		foreach ($subPermissions as $subPermission) {
			if ($subPermission) {
				if (!array_key_exists($subPermission, $tree)) {
					$tree[$subPermission] = array();
				}
				$tree =& $tree[$subPermission];
			}
		}
		$tree = array('description' => $description, 'location-aware' => $locationAware, 'isLeaf' => true);
	}

}