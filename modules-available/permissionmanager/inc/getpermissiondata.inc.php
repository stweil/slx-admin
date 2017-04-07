<?php

class GetPermissionData {

	// get UserIDs, User Login Names, User Roles
	public static function getUserData() {
		$res = self::queryUserData();
		$userdata= array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$userdata[$row['userid'].' '.$row['login']][] = array(
				'roleId' => $row['roleId'],
				'roleName' => $row['roleName']
			);
		}
		$data = array();
		foreach($userdata AS $user => $roles) {
			$user = explode(" ", $user, 2);
			$data[] = array(
				'userid' => $user[0],
				'username' => $user[1],
				'roles' => $roles
			);
		}
		return $data;
	}

	// get LocationIDs, Location Names, Roles of each Location
	public static function getLocationData() {
		$res = self::queryLocationData();
		$locdata = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$locdata[$row['locid'].' '.$row['locname']][] = array(
				'roleId' => $row['roleId'],
				'roleName' => $row['roleName']
			);
		}
		$data = array();
		foreach($locdata AS $loc => $roles) {
			$loc = explode(" ", $loc, 2);
			$data[] = array(
				'locid' => $loc[0],
				'locname' => $loc[1],
				'roles' => $roles
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

	public static function getRoleData($roleId) {
		$query = "SELECT id, name FROM role WHERE id = :roleId";
		$data = Database::queryFirst($query, array("roleId" => $roleId));
		$query = "SELECT roleid, locid FROM role_x_location WHERE roleid = :roleId";
		$res = Database::simpleQuery($query, array("roleId" => $roleId));
		$data["locations"] = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data["locations"][] = $row['locid'];
		}
		$query = "SELECT roleid, permissionid FROM role_x_permission WHERE roleid = :roleId";
		$res = Database::simpleQuery($query, array("roleId" => $roleId));
		$data["permissions"] = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data["permissions"][] = $row['permissionid'];
		}
		return $data;
	}

	// UserID, User Login Name, Roles of each User
	private static function queryUserData() {
		$res = Database::simpleQuery("SELECT user.userid AS userid, user.login AS login, role.name AS roleName, role.id AS roleId
												FROM user
													LEFT JOIN user_x_role ON user.userid = user_x_role.userid
													LEFT JOIN role ON user_x_role.roleid = role.id
												");
		return $res;
	}

	// LocationID, Location Name, Roles of each Location
	private static function queryLocationData() {
		$res = Database::simpleQuery("SELECT location.locationid AS locid, location.locationname AS locname, role.name AS roleName, role.id AS roleId
												FROM location
													LEFT JOIN role_x_location ON location.locationid = role_x_location.locid
													LEFT JOIN role ON role_x_location.roleid = role.id
												ORDER BY location.locationname
												");
		return $res;
	}

}