<?php

class PermissionDbUpdate {

	/**
	 * Insert all user/role combinations into the user_x_role table.
	 *
	 * @param array $users userids
	 * @param array $roles roleids
	 */
	public static function addRoleToUser($users, $roles) {
		$query = "INSERT IGNORE INTO user_x_role (userid, roleid) VALUES (:userid, :roleid)";
		foreach($users AS $userid) {
			foreach ($roles AS $roleid) {
				Database::exec($query, array("userid" => $userid, "roleid" => $roleid));
			}
		}
	}

	/**
	 * Remove all user/role combinations from the user_x_role table.
	 *
	 * @param array $users userids
	 * @param array $roles roleids
	 */
	public static function removeRoleFromUser($users, $roles) {
		$query = "DELETE FROM user_x_role WHERE userid IN (:users) AND roleid IN (:roles)";
		Database::exec($query, array("users" => $users, "roles" => $roles));
	}

	/**
	 * Delete role from the role table.
	 *
	 * @param string $roleid roleid
	 */
	public static function deleteRole($roleid) {
		Database::exec("DELETE FROM role WHERE roleid = :roleid", array("roleid" => $roleid));
	}

	/**
	 * Save changes to a role or create a new one.
	 *
	 * @param string $rolename rolename
	 * @param array $locations array of locations
	 * @param array $permissions array of permissions
	 * @param string|null $roleid roleid or null if the role does not exist yet
	 */
	public static function saveRole($rolename, $locations, $permissions, $roleid = NULL) {
		if ($roleid) {
			Database::exec("UPDATE role SET rolename = :rolename WHERE roleid = :roleid",
									array("rolename" => $rolename, "roleid" => $roleid));
			Database::exec("DELETE FROM role_x_location WHERE roleid = :roleid", array("roleid" => $roleid));
			Database::exec("DELETE FROM role_x_permission WHERE roleid = :roleid", array("roleid" => $roleid));
		} else {
			Database::exec("INSERT INTO role (rolename) VALUES (:rolename)", array("rolename" => $rolename));
			$roleid = Database::lastInsertId();
		}
		foreach ($locations as $locationid) {
			Database::exec("INSERT INTO role_x_location (roleid, locationid) VALUES (:roleid, :locationid)",
									array("roleid" => $roleid, "locationid" => $locationid));
		}
		foreach ($permissions as $permissionid) {
			Database::exec("INSERT INTO role_x_permission (roleid, permissionid) VALUES (:roleid, :permissionid)",
											array("roleid" => $roleid, "permissionid" => $permissionid));
		}
	}

}
