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
		$res = Database::simpleQuery("SELECT id, name FROM role ORDER BY name ASC");
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array(
				'roleId' => $row['id'],
				'roleName' => $row['name']
			);
		}
		return $data;
	}

	public static function getLocations($selected) {
		$res = Database::simplequery("SELECT locationid, locationname FROM location");
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array('locid' => $row['locationid'], 'locName' => $row['locationname'],
				'selected' => in_array($row['locationid'], $selected) ? "selected" : "");
		}
		return $data;
	}

	public static function getRoleData($roleID) {
		$query = "SELECT id, name, locType FROM role WHERE id = $roleID";
		$data = Database::queryFirst($query);
		$query = "SELECT roleid, locid FROM roleXlocation WHERE roleid = $roleID";
		$res = Database::simpleQuery($query);
		$data["locations"] = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data["locations"][] = $row['locid'];
		}
		$query = "SELECT roleid, permissionid FROM roleXpermission WHERE roleid = $roleID";
		$res = Database::simpleQuery($query);
		$data["permissions"] = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data["permissions"][] = $row['permissionid'];
		}
		return $data;
	}

	// UserID, User Login Name, Roles of each User
	private static function queryUserData() {
		$res = Database::simpleQuery("SELECT user.userid AS userid, user.login AS login, GROUP_CONCAT(role.name ORDER BY role.name ASC) AS role
												FROM user
													LEFT JOIN userXrole ON user.userid = userXrole.userid
													LEFT JOIN role ON userXrole.roleid = role.id
												GROUP BY user.userid
												");
		return $res;
	}

}