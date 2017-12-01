<?php

class InfoPanel
{

	/**
	 * Gets the config of the location.
	 *
	 * @param int $locationID ID of the location
	 * @param mixed $config the panel config will be returned here
	 * @return string|bool paneltype, false if not exists
	 */
	public static function getConfig($paneluuid, &$config)
	{
		$panel = Database::queryFirst('SELECT panelname, panelconfig, paneltype, locationids, lastchange FROM locationinfo_panel WHERE paneluuid = :paneluuid',
			compact('paneluuid'));

		if ($panel === false) {
			return false;
		}

		if ($panel['paneltype'] === 'URL') {
			// Shortcut for URL redirect
			$config = json_decode($panel['panelconfig'], true);
			return $panel['paneltype'];
		}

		$config = LocationInfo::defaultPanelConfig($panel['paneltype']);
		$locations = Location::getLocationsAssoc();
		$overrides = false;

		if (!empty($panel['panelconfig'])) {
			$json = json_decode($panel['panelconfig'], true);
			if (is_array($json)) {
				// Put location-specific overrides in separate variable for later use
				if (isset($json['overrides']) && is_array($json['overrides'])) {
					$overrides = $json['overrides'];
				}
				unset($json['overrides']);
				$config = $json + $config;
			}
		}
		if (isset($config['showtitle']) && $config['showtitle']) {
			$config['title'] = $panel['title'];
		}
		$config['locations'] = array();
		$lids = array_map('intval', explode(',', $panel['locationids']));
		foreach ($lids as $lid) {
			$config['locations'][$lid] = array(
				'id' => $lid,
				'name' => isset($locations[$lid]) ? $locations[$lid]['locationname'] : 'noname00.pas',
			);
			// Now apply any overrides from above
			if (isset($overrides[$lid]) && is_array($overrides[$lid])) {
				$config['locations'][$lid]['config'] = $overrides[$lid];
			}
		}
		self::appendMachineData($config['locations'], $lids, true);
		self::appendOpeningTimes($config['locations'], $lids);

		$config['ts'] = (int)$panel['lastchange'];
		$config['locations'] = array_values($config['locations']);
		$config['time'] = date('Y-n-j-G-') . (int)date('i') . '-' . (int)(date('s'));

		return $panel['paneltype'];
	}

	/**
	 * Gets the location info of the given locations.
	 * Append to passed array which is expected to
	 * map location ids to properties of that location.
	 * A new key 'machines' will be created in each
	 * entry of $array that will take all the machine data.
	 *
	 * @param array $array location list to populate with machine data
	 * @param bool $withPosition Defines if coords should be included or not.
	 */
	public static function appendMachineData(&$array, $idList = false, $withPosition = false)
	{
		if (empty($array) && $idList === false)
			return;
		if ($idList === false) {
			$idList = array_keys($array);
		}

		$ignoreList = array();
		if (Module::isAvailable('runmode')) {
			// Ignore clients with special runmode not marked as still being a client
			$ignoreList = RunMode::getAllClients(false, true);
		}

		$positionCol = $withPosition ? 'm.position,' : '';
		$query = "SELECT m.locationid, m.machineuuid, $positionCol m.logintime, m.lastseen, m.lastboot, m.state FROM machine m
				WHERE m.locationid IN (:idlist)";
		$dbquery = Database::simpleQuery($query, array('idlist' => $idList));

		// Iterate over matching machines
		while ($row = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			if (isset($ignoreList[$row['machineuuid']]))
				continue;
			settype($row['locationid'], 'int');
			if (!isset($array[$row['locationid']])) {
				$array[$row['locationid']] = array('id' => $row['locationid'], 'machines' => array());
			}
			if (!isset($array[$row['locationid']]['machines'])) {
				$array[$row['locationid']]['machines'] = array();
			}
			// Compact the pc data in one array.
			$pc = array('id' => $row['machineuuid']);
			if ($withPosition && !empty($row['position'])) {
				$position = json_decode($row['position'], true);
				if (isset($position['gridCol']) && isset($position['gridRow'])) {
					$pc['x'] = $position['gridCol'];
					$pc['y'] = $position['gridRow'];
					if (!empty($position['overlays']) && is_array($position['overlays'])) {
						$pc['overlays'] = $position['overlays'];
					}
				}
			}
			$pc['pcState'] = LocationInfo::getPcState($row);
			//$pc['pcState'] = ['BROKEN', 'OFFLINE', 'IDLE', 'OCCUPIED', 'STANDBY'][mt_rand(0,4)]; // XXX

			// Add the array to the machines list.
			$array[$row['locationid']]['machines'][] = $pc;
		}
	}

	/**
	 * Gets the Opening time of the given locations.
	 *
	 * @param array $array list of locations, indexed by locationId
	 * @param int[] $idList list of locations
	 */
	public static function appendOpeningTimes(&$array, $idList)
	{
		// First, lets get all the parent ids for the given locations
		// in case we need to get inherited opening times
		$allIds = self::getLocationsWithParents($idList);
		if (empty($allIds))
			return;
		$res = Database::simpleQuery("SELECT locationid, openingtime FROM locationinfo_locationconfig
		WHERE locationid IN (:lids)", array('lids' => $allIds));
		$openingTimes = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$openingTimes[(int)$row['locationid']] = $row;
		}
		// Now we got all the calendars for locations and parents
		// Iterate over the locations we're actually interested in
		$locations = Location::getLocationsAssoc();
		foreach ($idList as $locationId) {
			// Start checking at actual location...
			$currentId = $locationId;
			while ($currentId !== 0) {
				if (!empty($openingTimes[$currentId]['openingtime'])) {
					$cal = json_decode($openingTimes[$currentId]['openingtime'], true);
					if (is_array($cal)) {
						$cal = self::formatOpeningtime($cal);
					}
					if (!empty($cal)) {
						// Got a valid calendar
						if (!isset($array[$locationId])) {
							$array[$locationId] = array('id' => $locationId);
						}
						$array[$locationId]['openingtime'] = $cal;
						break;
					}
				}
				// Keep trying with parent
				$currentId = $locations[$currentId]['parentlocationid'];
			}
		}
		return;
	}


	/**
	 * Returns all the passed location ids and appends
	 * all their direct and indirect parent location ids.
	 *
	 * @param int[] $idList location ids
	 * @return  int[] more location ids
	 */
	private static function getLocationsWithParents($idList)
	{
		$locations = Location::getLocationsAssoc();
		$allIds = $idList;
		foreach ($idList as $id) {
			if (isset($locations[$id]) && isset($locations[$id]['parents'])) {
				$allIds = array_merge($allIds, $locations[$id]['parents']);
			}
		}
		return array_map('intval', $allIds);
	}

// ########## <Openingtime> ##########

	/**
	 * Format the openingtime in the frontend needed format.
	 * One key per week day, wich contains an array of {
	 * 'HourOpen' => hh, 'MinutesOpen' => mm,
	 * 'HourClose' => hh, 'MinutesClose' => mm }
	 *
	 * @param array $openingtime The opening time in the db saved format.
	 * @return mixed The opening time in the frontend needed format.
	 */
	private static function formatOpeningtime($openingtime)
	{
		$result = array();
		foreach ($openingtime as $entry) {
			$openTime = explode(':', $entry['openingtime']);
			$closeTime = explode(':', $entry['closingtime']);
			if (count($openTime) !== 2 || count($closeTime) !== 2)
				continue;
			$convertedTime = array(
				'HourOpen' => $openTime[0],
				'MinutesOpen' => $openTime[1],
				'HourClose' => $closeTime[0],
				'MinutesClose' => $closeTime[1],
			);
			foreach ($entry['days'] as $day) {
				if (!isset($result[$day])) {
					$result[$day] = array();
				}
				$result[$day][] = $convertedTime;
			}
		}
		return $result;
	}

}
