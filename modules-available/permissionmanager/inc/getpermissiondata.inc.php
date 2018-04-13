<?php

class GetPermissionData
{

	const WITH_USER_COUNT = 1;
	const WITH_LOCATION_COUNT = 2;

	/**
	 * Get data for all users.
	 *
	 * @return array array of users (each with userid, username and roles (each with roleid and rolename))
	 */
	public static function getUserData()
	{
		$res = Database::simpleQuery("SELECT user.userid AS userid, user.login AS login, role.rolename AS rolename, role.roleid AS roleid
												FROM user
												LEFT JOIN role_x_user ON user.userid = role_x_user.userid
												LEFT JOIN role ON role_x_user.roleid = role.roleid
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
	 * @param int $flags Bitmask specifying additional data to fetch (WITH_* constants of this class)
	 * @return array array roles (each with roleid and rolename)
	 */
	public static function getRoles($flags = 0)
	{
		$cols = $joins = '';
		if ($flags & self::WITH_USER_COUNT) {
			$cols .= ', Count(DISTINCT rxu.userid) AS users';
			$joins .= ' LEFT JOIN role_x_user rxu ON (r.roleid = rxu.roleid)';
		}
		if ($flags & self::WITH_LOCATION_COUNT) {
			$cols .= ', Count(DISTINCT rxl.locationid) AS locations';
			$joins .= ' LEFT JOIN role_x_location rxl ON (r.roleid = rxl.roleid)';
		}
		if (!empty($joins)) {
			$joins .= ' GROUP BY r.roleid';
		}
		return Database::queryAll("SELECT r.roleid, r.rolename, r.roledescription $cols FROM role r
			$joins
			ORDER BY rolename ASC");
	}

	/**
	 * Get permissions and locations for a given role.
	 *
	 * @param string $roleid id of the role
	 * @return array array containing an array of permissions and an array of locations
	 */
	public static function getRoleData($roleid)
	{
		$query = "SELECT roleid, rolename, roledescription FROM role WHERE roleid = :roleid";
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