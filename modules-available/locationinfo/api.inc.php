<?php

HandleParameters();

/**
 * Handles the API paramenters.
 */
function HandleParameters()
{

	$getAction = Request::get('action', 0, 'string');
	$output = false;
	if ($getAction == "locationinfo") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		$getCoords = Request::get('coords', false, 'bool');
		$output = getLocationInfo($array, $getCoords);
	} elseif ($getAction == "openingtime") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		$output = getOpeningTime($array);
	} elseif ($getAction == "config") {
		$locationId = Request::get('id', 0, 'int');
		$output = getConfig($locationId);
	} elseif ($getAction == "pcstates") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		$output = getPcStates($array);
	} elseif ($getAction == "locationtree") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		$output = getLocationTree($array);
	} elseif ($getAction == "calendar") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		$output = getCalendar($array);
	}
	if ($output !== false) {
		echo json_encode($output);
	}
}

/**
 * Filters the id list. Removes Double / non-int / hidden locations.
 *
 * @param string $locationIds comma separated list of location ids
 * @return array The filtered array of the location ids.
 */
function filterIdList($locationIds)
{
	$idList = explode(',', $locationIds);
	$filteredIdList = array_filter($idList, 'is_numeric');
	$filteredIdList = array_unique($filteredIdList);
	$filteredIdList = filterHiddenLocations($filteredIdList);

	return $filteredIdList;
}

/**
 * Filters the hidden locations from an array.
 *
 * @param int[] $idArray Id list
 * @return array Filtered id list
 */
function filterHiddenLocations($idArray)
{
	$idArray = array_flip($idArray);
	if (!empty($idArray)) {
		$query = "SELECT locationid FROM `locationinfo_locationconfig` WHERE hidden <> 0 AND locationid IN (:idlist)";
		$dbquery = Database::simpleQuery($query, array('idlist' => $idArray));
		while ($dbresult = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			unset($idArray[$dbresult['locationid']]);
		}
	}

	return array_flip($idArray);
}

// ########## <Locationinfo> ##########
/**
 * Gets the location info of the given locations.
 *
 * @param int[] $idList list of ids.
 * @param bool $coords Defines if coords should be included or not.
 * @return array location info struct
 */
function getLocationInfo($idList, $coords = false)
{
	if (empty($idList))
		return [];

		$positionCol = $coords ? 'm.position,' : '';
	$query = "SELECT m.locationid, m.machineuuid, $positionCol m.logintime, m.lastseen, m.lastboot FROM machine m
				WHERE m.locationid IN (:idlist)";
	$dbquery = Database::simpleQuery($query, array('idlist' => $idList));

	// Iterate over matching machines
	$dbresult = array();
	while ($dbdata = $dbquery->fetch(PDO::FETCH_ASSOC)) {

		// Set the id if the locationid changed.
		if (!isset($dbresult[$dbdata['locationid']])) {
			$dbresult[$dbdata['locationid']] = array('id' => $dbdata['locationid'], 'computer' => array());
		}
		// Compact the pc data in one array.
		$pc = array('id' => $dbdata['machineuuid']);
		if ($coords && !empty($dbdata['position'])) {
			$position = json_decode($dbdata['position'], true);
			if (isset($position['gridCol']) && isset($position['gridRow'])) {
				$pc['x'] = $position['gridCol'];
				$pc['y'] = $position['gridRow'];
				if (isset($position['overlays']) && is_array($position['overlays'])) {
					$pc['overlays'] = $position['overlays'];
				} else {
					$pc['overlays'] = array();
				}
			}
		}
		$pc['pcState'] = LocationInfo::getPcState($dbdata);

		// Add the array to the computer list.
		$dbresult[$dbdata['locationid']]['computer'][] = $pc;
	}

	// The array keys are only used for the isset -> Return only the values.
	return array_values($dbresult);
}

// ########## </Locationinfo> ###########

// ########## <Openingtime> ##########
/**
 * Gets the Opening time of the given locations.
 *
 * @param int[] $idList list of locations
 * @return array Opening times struct
 */
function getOpeningTime($idList)
{
	$locations = Location::getLocationsAssoc();
	$allIds = $idList;
	foreach ($idList as $id) {
		if (isset($locations[$id]) && isset($locations[$id]['parents'])) {
			$allIds = array_merge($allIds, $locations[$id]['parents']);
		}
	}
	if (empty($allIds))
		return [];
	$openingTimes = array();
	$qs = '?' . str_repeat(',?', count($allIds) - 1);
	$res = Database::simpleQuery("SELECT locationid, openingtime FROM locationinfo_locationconfig WHERE locationid IN ($qs)",
		array_values($allIds));
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$openingTimes[(int)$row['locationid']] = $row['openingtime'];
	}
	$returnValue = array();
	foreach ($idList as $locationid) {
		$id = $locationid;
		while ($id !== 0) {
			if (!empty($openingTimes[$id])) {
				$cal = json_decode($openingTimes[$id], true);
				if (is_array($cal)) {
					$cal = formatOpeningtime($cal);
				}
				if (!empty($cal)) {
					$returnValue[] = array(
						'id' => $locationid,
						'openingtime' => $cal,
					);
					break;
				}
			}
			$id = $locations[$id]['parentlocationid'];
		}
	}
	return $returnValue;
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
	$weekarray = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
	foreach ($weekarray as $checkDay) {
		$array = array();
		foreach ($openingtime as $opt) {
			if (!isset($opt['days']) || !is_array($opt['days']))
				continue;
			$openTime = explode(':', $opt['openingtime']);
			$closeTime = explode(':', $opt['closingtime']);
			if (count($openTime) !== 2 || count($closeTime) !== 2)
				continue;
			$arr = array(
				'HourOpen' => $openTime[0],
				'MinutesOpen' => $openTime[1],
				'HourClose' => $closeTime[0],
				'MinutesClose' => $closeTime[1],
			);
			foreach ($opt['days'] as $calDay) {
				if ($calDay === $checkDay) {
					$array[] = $arr;
				}
			}
			if (!empty($array)) {
				$result[$checkDay] = $array;
			}
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
function getConfig($locationID)
{
	$dbresult = Database::queryFirst("SELECT l.locationname, li.config FROM `location` AS l
		LEFT JOIN `locationinfo_locationconfig` AS li ON (l.locationid = li.locationid)
		WHERE l.locationid = :locationID", array('locationID' => $locationID));

	$config = defaultConfig();

	if ($dbresult !== false) {
		if (!empty($dbresult['config'])) {
			$json = json_decode($dbresult['config'], true);
			if (is_array($json)) {
				$config = $json + $config;
			}
		}
		$config['room'] = $dbresult['locationname'];
	}

	$config['time'] = date('Y-m-d H:i:s');

	return $config;
}

/**
 * Creates and returns a default config for room that didn't saved a config yet.
 *
 * @return array Return a default config.
 */
function defaultConfig()
{
	return array(
		'language' => 'en',
		'mode' => 1,
		'vertical' => false,
		'eco' => false,
		'scaledaysauto' => true,
		'daystoshow' => 7,
		'rotation' => 0,
		'scale' => 50,
		'switchtime' => 20,
		'calupdate' => 30,
		'roomupdate' => 5,
		'configupdate' => 180,
	);
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
	$locationInfoList = getLocationInfo($idList);
	foreach ($locationInfoList as $locationInfo) {
		$id = $locationInfo['id'];
		foreach ($locationInfo['computer'] as $computer) {
			$key = strtolower($computer['pcState']);
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
 * @return string Calendar JSON.
 */
function getCalendar($idList)
{
	if (empty($idList))
		return [];

	// Build SQL query for multiple ids.
	$query = "SELECT l.locationid, l.serverid, l.serverlocationid, s.servertype, s.credentials
				FROM `locationinfo_locationconfig` AS l
				INNER JOIN locationinfo_coursebackend AS s ON (s.serverid = l.serverid)
				WHERE l.hidden = 0 AND l.locationid IN (:idlist)
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
