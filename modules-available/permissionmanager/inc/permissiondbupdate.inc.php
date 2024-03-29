<?php

class PermissionDbUpdate
{

	/**
	 * Insert all user/role combinations into the role_x_user table.
	 *
	 * @param int[] $users userids
	 * @param int[] $roles roleids
	 */
	public static function addRoleToUser($users, $roles)
	{
		if (empty($users) || empty($roles))
			return 0;
		$arg = array();
		foreach ($users AS $userid) {
			foreach ($roles AS $roleid) {
				$arg[] = compact('userid', 'roleid');
			}
		}
		return Database::exec("INSERT IGNORE INTO role_x_user (userid, roleid) VALUES :arg",
			['arg' => $arg]);
	}

	/**
	 * Remove all user/role combinations from the role_x_user table.
	 *
	 * @param int[] $users userids
	 * @param int[] $roles roleids
	 */
	public static function removeRoleFromUser($users, $roles)
	{
		if (empty($users) || empty($roles))
			return 0;
		$query = "DELETE FROM role_x_user WHERE userid IN (:users) AND roleid IN (:roles)";
		return Database::exec($query, array("users" => $users, "roles" => $roles));
	}

	/**
	 * Assign the specified roles to given users, removing any roles from the users
	 * that are not in the given set.
	 *
	 * @param int[] $users list of user ids
	 * @param int[] $roles list of role ids
	 */
	public static function setRolesForUser($users, $roles)
	{
		$count = Database::exec("DELETE FROM role_x_user WHERE userid in (:users) AND roleid NOT IN (:roles)",
			compact('users', 'roles'));
		return $count + self::addRoleToUser($users, $roles);
	}

	/**
	 * Delete role from the role table.
	 *
	 * @param int $roleid roleid
	 */
	public static function deleteRole($roleid)
	{
		return Database::exec("DELETE FROM role WHERE roleid = :roleid", array("roleid" => $roleid));
	}

	/**
	 * Save changes to a role or create a new one.
	 *
	 * @param string $roleName rolename
	 * @param int[] $locations array of locations
	 * @param string[] $permissions array of permissions
	 * @param int|null $roleId roleid or null if the role does not exist yet
	 */
	public static function saveRole($roleName, $roleDescription, $locations, $permissions, $roleId = null)
	{
		foreach ($permissions as &$permission) {
			$permission = strtolower($permission);
		}
		unset($permission);
		if ($roleId) {
			Database::exec("UPDATE role SET rolename = :rolename, roledescription = :roledescription WHERE roleid = :roleid",
				array("rolename" => $roleName, "roledescription" => $roleDescription, "roleid" => $roleId));
			Database::exec("DELETE FROM role_x_location
					WHERE roleid = :roleid AND (locationid NOT IN (:locations) OR locationid IS NULL)",
				array("roleid" => $roleId, 'locations' => $locations));
			Database::exec("DELETE FROM role_x_permission
					WHERE roleid = :roleid AND permissionid NOT IN (:permissions)",
				array("roleid" => $roleId, 'permissions' => $permissions));
		} else {
			Database::exec("INSERT INTO role (rolename, roledescription) VALUES (:rolename, :roledescription)",
				array("rolename" => $roleName, "roledescription" => $roleDescription));
			$roleId = Database::lastInsertId();
		}

		if (!empty($locations)) {
			$arg = array_map(function ($loc) use ($roleId) {
				return compact('roleId', 'loc');
			}, $locations);
			Database::exec("INSERT IGNORE INTO role_x_location (roleid, locationid) VALUES :arg", ['arg' => $arg]);
		}

		if (!empty($permissions)) {
			$arg = array_map(function ($perm) use ($roleId) {
				return compact('roleId', 'perm');
			}, $permissions);
			Database::exec("INSERT IGNORE INTO role_x_permission (roleid, permissionid) VALUES :arg", ['arg' => $arg]);
		}
	}

}
