<?php

class GetPermissionData {

	// get UserIDs, User Login Names, User Roles
	public static function getUserData() {
		$res = self::queryUserData();
		$userdata= array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$userdata[$row['userid'].' '.$row['login']][] = array(
				'roleid' => $row['roleid'],
				'rolename' => $row['rolename']
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
		$res = Database::simpleQuery("SELECT role.roleid as roleid, rolename, GROUP_CONCAT(locationid) as locationids FROM role
 												LEFT JOIN (SELECT roleid, COALESCE(locationid, 0) AS locationid FROM role_x_location) rxl
 												ON role.roleid = rxl.roleid GROUP BY roleid ORDER BY rolename ASC");
		$locations = Location::getLocations(0, 0, false, true);
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$locationids = explode(",", $row['locationids']);
			if (in_array("0", $locationids)) {
				$locationids = array_map("intval", Location::extractIds(Location::getTree()));
			} else {
				$locationids = PermissionUtil::getSublocations(Location::getTree(), $locationids);
			}
			foreach ($locationids as $locationid) {
				$locations[$locationid]['roles'][] = array(
					'roleid' => $row['roleid'],
					'rolename' => $row['rolename']
				);
			}
		}
		return array_values($locations);
	}

	// get all roles from database (id and name)
	public static function getRoles() {
		$res = Database::simpleQuery("SELECT roleid, rolename FROM role ORDER BY rolename ASC");
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array(
				'roleid' => $row['roleid'],
				'rolename' => $row['rolename']
			);
		}
		return $data;
	}

	public static function getRoleData($roleid) {
		$query = "SELECT roleid, rolename FROM role WHERE roleid = :roleid";
		$data = Database::queryFirst($query, array("roleid" => $roleid));
		$query = "SELECT roleid, locationid FROM role_x_location WHERE roleid = :roleid";
		$res = Database::simpleQuery($query, array("roleid" => $roleid));
		$data["locations"] = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data["locations"][] = $row['locationid'];
		}
		$query = "SELECT roleid, permissionid FROM role_x_permission WHERE roleid = :roleid";
		$res = Database::simpleQuery($query, array("roleid" => $roleid));
		$data["permissions"] = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data["permissions"][] = $row['permissionid'];
		}
		return $data;
	}

	// UserID, User Login Name, Roles of each User
	private static function queryUserData() {
		$res = Database::simpleQuery("SELECT user.userid AS userid, user.login AS login, role.rolename AS rolename, role.roleid AS roleid
												FROM user
													LEFT JOIN user_x_role ON user.userid = user_x_role.userid
													LEFT JOIN role ON user_x_role.roleid = role.roleid
												");
		return $res;
	}

}