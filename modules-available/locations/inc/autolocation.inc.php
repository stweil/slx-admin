<?php

class AutoLocation
{

	public static function rebuildAll()
	{
		if (Module::get('statistics') === false)
			return; // Nothing to do
		$res = Database::simpleQuery("SELECT machineuuid, clientip FROM machine");
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
			$qs = '?' . str_repeat(',?', count($nulls) - 1);
			Database::exec("UPDATE machine SET subnetlocationid = NULL WHERE machineuuid IN ($qs)", $nulls);
		}
		foreach ($updates as $lid => $machines) {
			$qs = '?' . str_repeat(',?', count($machines) - 1);
			$lid = (int)$lid;
			Database::exec("UPDATE machine SET subnetlocationid = $lid WHERE machineuuid IN ($qs)", $machines);
		}
	}

}
