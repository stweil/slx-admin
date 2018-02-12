<?php

class GetPermissionData
{

	/**
	 * Get data for all users.
	 *
	 * @return array array of users (each with userid, username and roles (each with roleid and rolename))
	 */
	public static function getUserData()
	{
		$res = Database::simpleQuery("SELECT user.userid AS userid, user.login AS login, role.rolename AS rolename, role.roleid AS roleid
												FROM user
												LEFT JOIN user_x_role ON user.userid = user_x_role.userid
												LEFT JOIN role ON user_x_role.roleid = role.roleid
												");
		$userdata = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$userdata[$row['userid'] . ' ' . $row['login']][] = array(
				'roleid' => $row['roleid'],
				'rolename' => $row['rolename']
			);
		}
		$data = array();
		foreach ($userdata AS $user => $roles) {
			$user = explode(" ", $user, 2);
			$data[] = array(
				'userid' => $user[0],
				'username' => $user[1],
				'roles' => $roles
			);
		}
		return $data;
	}

	/**
	 * Get data for all locations.
	 *
	 * @return array array of locations (each including the roles that have permissions for them)
	 */
	public static function getLocationData()
	{
		$res = Database::simpleQuery("SELECT role.roleid AS roleid, rolename, GROUP_CONCAT(COALESCE(locationid, 0)) AS locationids FROM role
 												INNER JOIN role_x_location ON role.roleid = role_x_location.roleid GROUP BY roleid ORDER BY rolename ASC");
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

	/**
	 * Get all roles.
	 *
	 * @return array array roles (each with roleid and rolename)
	 */
	public static function getRoles()
	{
		return Database::queryAll("SELECT roleid, rolename FROM role ORDER BY rolename ASC");
	}

	/**
	 * Get permissions and locations for a given role.
	 *
	 * @param string $roleid id of the role
	 * @return array array containing an array of permissions and an array of locations
	 */
	public static function getRoleData($roleid)
	{
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

}