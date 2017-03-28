<?php

class GetData {

	// get UserIDs, User Login Names, User Roles
	public static function getUserData() {
		$res = self::queryUserData();
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array(
				'userid' => $row['userid'],
				'name' => $row['login'],
				'role' => explode(",",$row['role'])
			);
		}
		return $data;
	}

	// get all roles from database (id and name)
	public static function getRoles() {
		$res = Database::simpleQuery("SELECT id, name FROM role ORDER BY name");
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array(
				'roleId' => $row['id'],
				'roleName' => $row['name']
			);
		}
		return $data;
	}

	// UserID, User Login Name, Roles of each User
	private static function queryUserData() {
		$res = Database::simpleQuery("SELECT user.userid AS userid, user.login AS login, GROUP_CONCAT(role.name) AS role
												FROM user
													LEFT JOIN userXrole ON user.userid = userXrole.userid
													LEFT JOIN role ON userXrole.roleid = role.id
												GROUP BY user.userid
												");
		return $res;
	}

}