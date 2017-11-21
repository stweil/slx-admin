<?php

class PermissionDbUpdate {

	// insert new user_x_role to database. "ignore" to ignore duplicate entry try
	public static function addRoleToUser($users, $roles) {
		$query = "INSERT IGNORE INTO user_x_role (userid, roleid) VALUES (:userid, :roleid)";
		foreach($users AS $userid) {
			foreach ($roles AS $roleid) {
				Database::exec($query, array("userid" => $userid, "roleid" => $roleid));
			}
		}
	}

	// remove user_x_role entry from database
	public static function removeRoleFromUser($users, $roles) {
		$query = "DELETE FROM user_x_role WHERE userid IN (:users) AND roleid IN (:roles)";
		Database::exec($query, array("users" => $users, "roles" => $roles));
	}

	// delete role, delete user_x_role relationships, delete role_x_location relationships, delete role_x_permission relationships
	public static function deleteRole($roleid) {
		$query = "DELETE FROM role WHERE roleid = :roleid";
		Database::exec($query, array("roleid" => $roleid));
		$query = "DELETE FROM user_x_role WHERE roleid = :roleid";
		Database::exec($query, array("roleid" => $roleid));
		$query = "DELETE FROM role_x_location WHERE roleid = :roleid";
		Database::exec($query, array("roleid" => $roleid));
		$query = "DELETE FROM role_x_permission WHERE roleid = :roleid";
		Database::exec($query, array("roleid" => $roleid));
	}

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
