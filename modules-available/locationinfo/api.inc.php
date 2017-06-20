<?php

HandleParameters();

/**
 * Handles the API paramenters.
 */
function HandleParameters()
{

	$get = Request::get('get', 0, 'string');
	$uuid = Request::get('uuid', false, 'string');
	$output = false;
	if ($get === "machines") {
		$locationIds = getLocationsOr404($uuid);
		$output = array();
		appendMachineData($output, $locationIds, false);
		$output = array_values($output);
	} elseif ($get === "timestamp") {
		$output = array('ts' => getLastChangeTs($uuid));
	} elseif ($get === "config") {
		$output = getConfig($uuid);
	} elseif ($get === "pcstates") {
		$locationIds = getLocationsOr404($uuid);
		$output = getPcStates($locationIds);
	} elseif ($get === "locationtree") {
		$locationIds = getLocationsOr404($uuid);
		$output = getLocationTree($locationIds);
	} elseif ($get === "calendar") {
		$locationIds = getLocationsOr404($uuid);
		$output = getCalendar($locationIds);
	}
	if ($output !== false) {
		echo json_encode($output);
	} else {
		http_response_code(404);
		echo 'Unknown get option';
	}
}

/**
 * Return list of locationids associated with given panel.
 * @param string $paneluuid panel
 * @return int[] locationIds
 */
function getLocationsOr404($paneluuid)
{
	$panel = Database::queryFirst('SELECT locationids FROM locationinfo_panel WHERE paneluuid = :paneluuid',
		compact('paneluuid'));
	if ($panel !== false) {
		return array_map('intval', explode(',', $panel['locationids']));
	}
	http_response_code(404);
	die('Panel not found');
}

// ########## <Locationinfo> ##########
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
function appendMachineData(&$array, $idList = false, $withPosition = false)
{
	if (empty($array) && $idList === false)
		return;
	if ($idList === false) {
		$idList = array_keys($array);
	}

		$positionCol = $withPosition ? 'm.position,' : '';
	$query = "SELECT m.locationid, m.machineuuid, $positionCol m.logintime, m.lastseen, m.lastboot FROM machine m
				WHERE m.locationid IN (:idlist)";
	$dbquery = Database::simpleQuery($query, array('idlist' => $idList));

	// Iterate over matching machines
	while ($row = $dbquery->fetch(PDO::FETCH_ASSOC)) {
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

		// Add the array to the machines list.
		$array[$row['locationid']]['machines'][] = $pc;
	}
}

// ########## </Locationinfo> ###########

/**
 * Returns all the passed location ids and appends
 * all their direct and indirect parent location ids.
 *
 * @param int[] $idList location ids
 * @return  int[] more location ids
 */
function getLocationsWithParents($idList)
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
 * Gets the Opening time of the given locations.
 *
 * @param int[] $idList list of locations
 * @return int modification timestamp of most recently updated locationconfig
 */
function appendOpeningTimes(&$array, $idList)
{
	// First, lets get all the parent ids for the given locations
	// in case we need to get inherited opening times
	$allIds = getLocationsWithParents($idList);
	if (empty($allIds))
		return 0;
	$latest = 0;
	$res = Database::simpleQuery("SELECT locationid, openingtime, lastchange FROM locationinfo_locationconfig
		WHERE locationid IN (:lids)", array('lids' => $allIds));
	$openingTimes = array();
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$openingTimes[(int)$row['locationid']] = $row;
		if (in_array($row['locationid'], $idList)) {
			$latest = max($row['lastchange'], $latest);
		}
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
					$cal = formatOpeningtime($cal);
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
	return $latest;
}

/**
 * Format the openingtime in the frontend needed format.
 * One key per week day, wich contains an array of {
 * 'HourOpen' => hh, 'MinutesOpen' => mm,
 * 'HourClose' => hh, 'MinutesClose' => mm }
 *
 * @param array $openingtime The opening time in the db saved format.
 * @return mixed The opening time in the frontend needed format.
 */
function formatOpeningtime($openingtime)
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

// ########## </Openingtime> ##########
/**
 * Gets the config of the location.
 *
 * @param int $locationID ID of the location
 * @return array configuration struct
 */
function getConfig($paneluuid)
{
	$panel = Database::queryFirst('SELECT panelconfig, paneltype, locationids, lastchange FROM locationinfo_panel WHERE paneluuid = :paneluuid',
		compact('paneluuid'));

	if ($panel === false || empty($panel['locationids'])) {
		http_response_code(404);
		die('Panel not found');
	}

	$config = LocationInfo::defaultPanelConfig($panel['paneltype']);
	$locations = Location::getLocationsAssoc();
	$overrides = false;

	if (!empty($panel['panelconfig'])) {
		$json = json_decode($panel['panelconfig'], true);
		if (is_array($json)) {
			if (isset($json['overrides']) && is_array($json['overrides'])) {
				$overrides = $json['overrides'];
			}
			unset($json['overrides']);
			$config = $json + $config;
		}
	}
	$config['locations'] = array();
	$lids = array_map('intval', explode(',', $panel['locationids']));
	foreach ($lids as $lid) {
		$config['locations'][$lid] = array(
			'id' => $lid,
			'name' => isset($locations[$lid]) ? $locations[$lid]['locationname'] : 'noname00.pas',
		);
		if (isset($overrides[$lid]) && is_array($overrides[$lid])) {
			$config['locations'][$lid]['overrides'] = $overrides[$lid];
		}
	}
	appendMachineData($config['locations'], $lids, true);
	$locChange = appendOpeningTimes($config['locations'], $lids);

	$config['ts'] = max($panel['lastchange'], $locChange);
	$config['locations'] = array_values($config['locations']);
	$config['time'] = date('Y-m-d H:i:s');

	return $config;
}

/**
 * Get last config modification timestamp for given panel. This checks
 * the modification of the panel config itself as well as all involved locations
 *
 * @param $paneluuid
 * @return mixed
 */
function getLastChangeTs($paneluuid)
{
	$panel = Database::queryFirst('SELECT paneltype, locationids, lastchange FROM locationinfo_panel WHERE paneluuid = :paneluuid',
		compact('paneluuid'));
	if ($panel === false) {
		http_response_code(404);
		die('Panel not found');
	}
	$latest = $panel['lastchange'];
	// TODO: summary
	$lids = explode(',', $panel['locationids']);
	$res = Database::simpleQuery('SELECT lastchange FROM locationinfo_locationconfig WHERE locationid IN (:lids)',
		compact('lids'));
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$latest = max($row['lastchange'], $latest);
	}
	return (int)$latest;
}

/**
 * Gets the pc states of the given locations.
 *
 * @param int[] $idList list of the location ids.
 * @return array aggregated PC states
 */
function getPcStates($idList)
{
	$pcStates = array();
	foreach ($idList as $id) {
		$pcStates[$id] = array(
			'id' => $id,
			'idle' => 0,
			'occupied' => 0,
			'off' => 0,
			'broken' => 0,
		);
	}

	$locationInfoList = array();
	appendMachineData($locationInfoList, $idList);
	foreach ($locationInfoList as $locationInfo) {
		$id = $locationInfo['id'];
		foreach ($locationInfo['machines'] as $pc) {
			$key = strtolower($pc['pcState']);
			if (isset($pcStates[$id][$key])) {
				$pcStates[$id][$key]++;
			}
		}
	}

	return array_values($pcStates);
}

/**
 * Gets the location tree of the given locations.
 *
 * @param int[] $idList Array list of the locations.
 * @return array location tree data
 */
function getLocationTree($idList)
{
	if (in_array(0, $idList)) {
		return array_values(Location::getTree());
	}
	$locations = Location::getTree();

	$ret = findLocations($locations, $idList);
	return $ret;
}

function findLocations($locations, $idList)
{
	$ret = array();
	foreach ($locations as $location) {
		if (in_array($location['locationid'], $idList)) {
			$ret[] = $location;
		} elseif (!empty($location['children'])) {
			$ret = array_merge($ret, findLocations($location['children'], $idList));
		}
	}
	return $ret;
}

// ########## <Calendar> ###########
/**
 * Gets the calendar of the given ids.
 *
 * @param int[] $idList list with the location ids.
 * @return array Calendar.
 */
function getCalendar($idList)
{
	if (empty($idList))
		return [];

	// Build SQL query for multiple ids.
	$query = "SELECT l.locationid, l.serverid, l.serverlocationid, s.servertype, s.credentials
				FROM `locationinfo_locationconfig` AS l
				INNER JOIN locationinfo_coursebackend AS s ON (s.serverid = l.serverid)
				WHERE l.locationid IN (:idlist)
				ORDER BY s.servertype ASC";
	$dbquery = Database::simpleQuery($query, array('idlist' => array_values($idList)));

	$serverList = array();
	while ($dbresult = $dbquery->fetch(PDO::FETCH_ASSOC)) {
		if (!isset($serverList[$dbresult['serverid']])) {
			$serverList[$dbresult['serverid']] = array(
				'credentials' => json_decode($dbresult['credentials'], true),
				'type' => $dbresult['servertype'],
				'idlist' => array()
			);
		}
		$serverList[$dbresult['serverid']]['idlist'][] = $dbresult['locationid'];
	}

	$resultArray = array();
	foreach ($serverList as $serverid => $server) {
		$serverInstance = CourseBackend::getInstance($server['type']);
		if ($serverInstance === false) {
			EventLog::warning('Cannot fetch schedule for locationid ' . $server['locationid']
				. ': Backend type ' . $server['type'] . ' unknown. Disabling location.');
			Database::exec("UPDATE locationinfo_locationconfig SET serverid = 0 WHERE locationid = :lid",
				array('lid' => $server['locationid']));
			continue;
		}
		$credentialsOk = $serverInstance->setCredentials($serverid, $server['credentials']);

		if ($credentialsOk) {
			$calendarFromBackend = $serverInstance->fetchSchedule($server['idlist']);
		} else {
			$calendarFromBackend = array();
		}

		LocationInfo::setServerError($serverid, $serverInstance->getError());

		if (is_array($calendarFromBackend)) {
			foreach ($calendarFromBackend as $key => $value) {
				$resultArray[] = array(
					'id' => $key,
					'calendar' => $value,
				);
			}
		}
	}
	return $resultArray;
}

// ########## </Calendar> ##########
