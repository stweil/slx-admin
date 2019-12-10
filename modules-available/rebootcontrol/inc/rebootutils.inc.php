<?php

class RebootUtils
{

	/**
	 * Get machines by list of UUIDs
	 * @param string[] $list list of system UUIDs
	 * @return array list of machines with machineuuid, hostname, clientip, state and locationid
	 */
	public static function getMachinesByUuid($list, $assoc = false, $columns = ['machineuuid', 'hostname', 'clientip', 'state', 'locationid'])
	{
		if (empty($list))
			return array();
		if (is_array($columns)) {
			$columns = implode(',', $columns);
		}
		$res = Database::simpleQuery("SELECT $columns FROM machine
				WHERE machineuuid IN (:list)", compact('list'));
		if (!$assoc)
			return $res->fetchAll(PDO::FETCH_ASSOC);
		$ret = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$ret[$row['machineuuid']] = $row;
		}
		return $ret;
	}

	/**
	 * Sort list of clients so that machines that are up and running come first.
	 * Requires the array elements to have key "state" from machine table.
	 * @param array $clients list of clients
	 */
	public static function sortRunningFirst(&$clients)
	{
		usort($clients, function($a, $b) {
			$a = ($a['state'] === 'IDLE' || $a['state'] === 'OCCUPIED');
			$b = ($b['state'] === 'IDLE' || $b['state'] === 'OCCUPIED');
			if ($a === $b)
				return 0;
			return $a ? -1 : 1;
		});
	}

	/**
	 * Query list of clients (by uuid), taking user context into account, by filtering
	 * by given $permission.
	 * @param array $requestedClients list of uuids
	 * @param string $permission name of location-aware permission to check
	 * @return array|false List of clients the user has access to.
	 */
	public static function getFilteredMachineList($requestedClients, $permission)
	{
		$actualClients = RebootUtils::getMachinesByUuid($requestedClients);
		if (count($actualClients) !== count($requestedClients)) {
			// We could go ahead an see which ones were not found in DB but this should not happen anyways unless the
			// user manipulated the request
			Message::addWarning('some-machine-not-found');
		}
		// Filter ones with no permission
		foreach (array_keys($actualClients) as $idx) {
			if (!User::hasPermission($permission, $actualClients[$idx]['locationid'])) {
				Message::addWarning('locations.no-permission-location', $actualClients[$idx]['locationid']);
				unset($actualClients[$idx]);
			}
		}
		// See if anything is left
		if (!is_array($actualClients) || empty($actualClients)) {
			Message::addError('no-clients-selected');
			return false;
		}
		return $actualClients;
	}

}