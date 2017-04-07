<?php

class PermissionDbUpdate {

	// insert new user_x_role to database. "ignore" to ignore duplicate entry try
	public static function addRoleToUser($users, $roles) {
		foreach($users AS $user) {
			foreach ($roles AS $role) {
				$query = "INSERT IGNORE INTO user_x_role (userid, roleid) VALUES (:user, :role)";
				Database::exec($query, array("user" => $user, "role" => $role));
			}
		}
	}

	// remove user_x_role entry from database
	public static function removeRoleFromUser($users, $roles) {
		foreach($users AS $user) {
			foreach ($roles AS $role) {
				$query = "DELETE FROM user_x_role WHERE userid = :user AND roleid = :role";
				Database::exec($query, array("user" => $user, "role" => $role));
			}
		}
	}

	// delete role, delete user_x_role relationships, delete role_x_location relationships, delete role_x_permission relationships
	public static function deleteRole($id) {
		$query = "DELETE FROM role WHERE id = :id";
		Database::exec($query, array("id" => $id));
		$query = "DELETE FROM user_x_role WHERE roleid = :id";
		Database::exec($query, array("id" => $id));
		$query = "DELETE FROM role_x_location WHERE roleid = :id";
		Database::exec($query, array("id" => $id));
		$query = "DELETE FROM role_x_permission WHERE roleid = :id";
		Database::exec($query, array("id" => $id));
	}

	public static function saveRole($roleName, $locations, $permissions, $role = NULL) {
		if ($role) {
			Database::exec("UPDATE role SET name = :roleName WHERE id = :role",
									array("roleName" => $roleName, "role" => $role));
			Database::exec("DELETE FROM role_x_location WHERE roleid = :role", array("role" => $role));
			Database::exec("DELETE FROM role_x_permission WHERE roleid = :role", array("role" => $role));
		} else {
			Database::exec("INSERT INTO role (name) VALUES (:roleName)", array("roleName" => $roleName));
			$role = Database::lastInsertId();
		}
		foreach ($locations as $locID) {
			Database::exec("INSERT INTO role_x_location (roleid, locid) VALUES (:role, :locid)",
									array("role" => $role, "locid" => $locID));
		}
		foreach ($permissions as $permission) {
			Database::exec("INSERT INTO role_x_permission (roleid, permissionid) VALUES (:role, :permission)",
											array("role" => $role, "permission" => $permission));
		}
	}

}
