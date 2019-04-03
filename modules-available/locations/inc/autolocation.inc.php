<?php

class AutoLocation
{

	/**
	 * Rebuild the assigned subnetlocationid for clients that currently map to
	 * the given locationids (if given), or all clients, if locationid is false
	 *
	 * @param int[]|false $locations Locations to rebuild, or false for everything
	 */
	public static function rebuildAll($locations = false)
	{
		if (Module::get('statistics') === false)
			return; // Nothing to do
		if ($locations === false) {
			// All
			$res = Database::simpleQuery("SELECT machineuuid, clientip FROM machine");
		} else {
			$res = Database::simpleQuery("SELECT machineuuid, clientip FROM machine
					WHERE fixedlocationid IN(:lid) OR subnetlocationid IN(:lid)", ['lid' => $locations]);
		}
		$updates = array();
		$nulls = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$loc = Location::mapIpToLocation($row['clientip']);
			if ($loc === false) {
				$nulls[] = $row['machineuuid'];
			} else {
				if (!isset($updates[$loc])) {
					$updates[$loc] = array();
				}
				$updates[$loc][] = $row['machineuuid'];
			}
		}
		if (!empty($nulls)) {
			Database::exec("UPDATE machine SET subnetlocationid = NULL WHERE machineuuid IN (:nulls)",
				['nulls' => $nulls]);
		}
		foreach ($updates as $lid => $machines) {
			if (empty($machines))
				continue;
			Database::exec("UPDATE machine SET subnetlocationid = :lid WHERE machineuuid IN (:machines)",
				['lid' => $lid, 'machines' => $machines]);
		}
	}

}
