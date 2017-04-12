<?php

class RebootQueries
{

	// Get Client+IP+CurrentVM+CurrentUser+Location to fill the table
	public static function getMachineTable($locationId) {
		if ($locationId === 0) {
			$where = 'machine.locationid IS NULL';
		} else {
			$where = 'machine.locationid = :locationid';
		}
		$leftJoin = '';
		$sessionField = 'machine.currentsession';
		if (Module::get('dozmod') !== false) {
			// SELECT lectureid, displayname FROM sat.lecture WHERE lectureid = :lectureid
			$leftJoin = 'LEFT JOIN sat.lecture ON (lecture.lectureid = machine.currentsession)';
			$sessionField = 'IFNULL(lecture.displayname, machine.currentsession) AS currentsession';
		}
		$res = Database::simpleQuery("
			SELECT machine.machineuuid, machine.hostname, machine.clientip,
				IF(machine.lastboot = 0 OR UNIX_TIMESTAMP() - machine.lastseen >= 600, 0, 1) AS status,
				$sessionField, machine.currentuser, machine.locationid
			FROM machine 
			$leftJoin
			WHERE " . $where, array('locationid' => $locationId));
		return $res->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get machines by list of UUIDs
	 * @param string[] $list list of system UUIDs
	 * @return array list of machines with machineuuid, clientip and locationid
	 */
	public static function getMachinesByUuid($list)
	{
		if (empty($list))
			return array();
		$qs = '?' . str_repeat(',?', count($list) - 1);
		$res = Database::simpleQuery("SELECT machineuuid, clientip, locationid FROM machine WHERE machineuuid IN ($qs)", $list);
		return $res->fetchAll(PDO::FETCH_ASSOC);
	}

}