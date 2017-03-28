<?php

class DbUpdate {

	// insert new userXrole to database. "ignore" to ignore duplicate entry try
	public static function addRoleToUser($users, $roles) {
		foreach($users AS $user) {
			foreach ($roles AS $role) {
				$query = "INSERT IGNORE INTO userXrole (userid, roleid) VALUES ($user, $role)";
				Database::exec($query);
			}
		}
	}

	// remove userXrole entry from database
	public static function removeRoleFromUser($users, $roles) {
		foreach($users AS $user) {
			foreach ($roles AS $role) {
				$query = "DELETE FROM userXrole WHERE userid = $user AND roleid = $role";
				Database::exec($query);
			}
		}
	}

	// delete role, delete userXrole relationships, delete roleXlocation relationships, delete roleXpermission relationships
	public static function deleteRole($id) {
		$query = "DELETE FROM role WHERE id = $id";
		Database::exec($query);
		$query = "DELETE FROM userXrole WHERE roleid = $id";
		Database::exec($query);
		$query = "DELETE FROM roleXlocation WHERE roleid = $id";
		Database::exec($query);
		$query = "DELETE FROM roleXpermission WHERE roleid = $id";
		Database::exec($query);
	}

}
