<?php

class RebootQueries
{

	// Get Client+IP+CurrentVM+CurrentUser+Location to fill the table
	public static function getMachineTable($locationId) {
		$queryArgs = array('cutoff' => strtotime('-30 days'));
		if ($locationId === 0) {
			$where = 'machine.locationid IS NULL';
		} else {
			$where = 'machine.locationid = :locationid';
			$queryArgs['locationid'] = $locationId;
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
				machine.lastboot, machine.lastseen, machine.logintime,
				$sessionField, machine.currentuser, machine.locationid
			FROM machine 
			$leftJoin
			WHERE $where AND machine.lastseen > :cutoff", $queryArgs);
		$ret = $res->fetchAll(PDO::FETCH_ASSOC);
		$NOW = time();
		foreach ($ret as &$row) {
			if ($row['lastboot'] == 0 || $NOW - $row['lastseen'] > 600) {
				$row['status'] = 0;
			} else {
				$row['status'] = 1;
			}
			if ($row['status'] === 0 || $row['logintime'] == 0) {
				$row['currentuser'] = '';
				$row['currentsession'] = '';
			}
		}
		return $ret;
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
		$res = Database::simpleQuery("SELECT machineuuid, clientip, locationid FROM machine
				WHERE machineuuid IN (:list)", compact('list'));
		return $res->fetchAll(PDO::FETCH_ASSOC);
	}

}