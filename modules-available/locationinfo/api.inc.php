<?php

HandleParameters();

/**
 * Handles the API paramenters.
 */
function HandleParameters()
{

	$getAction = Request::get('action', 0, 'string');
	if ($getAction == "locationinfo") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		$getCoords = Request::get('coords', 0, 'string');
		if (empty($getCoords)) {
			$getCoords = '0';
		}
		echo getLocationInfo($array, $getCoords);
	} elseif ($getAction == "openingtime") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		echo getOpeningTime($array);
	} elseif ($getAction == "config") {
		$locationId = Request::get('id', 0, 'int');
		getConfig($locationId);
	} elseif ($getAction == "pcstates") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		echo getPcStates($array);
	} elseif ($getAction == "locationtree") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		echo getLocationTree($array);
	} elseif ($getAction == "calendar") {
		$locationIds = Request::get('id', 0, 'string');
		$array = filterIdList($locationIds);
		echo getCalendar($array);
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
 * @param $idArray Id list
 * @return array Filtered id list
 */
function filterHiddenLocations($idArray)
{
	$filteredArray = array();
	if (!empty($idArray)) {
		$query = "SELECT locationid, hidden FROM `locationinfo_locationconfig` WHERE locationid IN (";
		$query .= implode(",", $idArray);
		$query .= ")";

		$dbquery = Database::simpleQuery($query);

		while ($dbresult = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			if ($dbresult['hidden'] == false) {
				$filteredArray[] = (int)$dbresult['locationid'];
			}
		}
	}

	return $filteredArray;
}

// ########## <Locationinfo> ##########
/**
 * Gets the location info of the given locations.
 *
 * @param $idList Array list of ids.
 * @param bool $coords Defines if coords should be included or not.
 * @return string location info, formatted as JSON
 */
function getLocationInfo($idList, $coords = false)
{

	$coordinates = (string)$coords;
	$dbresult = array();

	if (!empty($idList)) {
		// Build SQL Query for multiple ids.
		$query = "SELECT l.locationid, m.machineuuid, m.position, m.logintime, m.lastseen, m.lastboot FROM locationinfo_locationconfig AS l
				LEFT JOIN `machine` AS m ON l.locationid = m.locationid WHERE l.hidden = 0 AND l.locationid IN (";

		$query .= implode(",", $idList);
		$query .= ")";

		// Execute query.
		$dbquery = Database::simpleQuery($query);

		// Fetch db data.
		while ($dbdata = $dbquery->fetch(PDO::FETCH_ASSOC)) {

			// Set the id if the locationid changed.
			if (!isset($dbresult[$dbdata['locationid']])) {
				$dbresult[$dbdata['locationid']] = array('id' => $dbdata['locationid'], 'computer' => array());
			}

			// Left join, no data
			if (empty($dbdata['machineuuid']))
				continue;

			// Compact the pc data in one array.
			$pc['id'] = $dbdata['machineuuid'];
			if ($coordinates == '1' || $coordinates == 'true') {
				$position = json_decode($dbdata['position'], true);
				$pc['x'] = $position['gridCol'];
				$pc['y'] = $position['gridRow'];

				if (isset($position['overlays'])) {
					$pc['overlay'] = $position['overlays'];
				} else {
					$pc['overlay'] = array();
				}
			}
			$pc['pcState'] = LocationInfo::getPcState($dbdata);

			// Add the array to the computer list.
			$dbresult[$dbdata['locationid']]['computer'][] = $pc;
		}
	}

	// The array keys are only used for the isset -> Return only the values.
	return json_encode(array_values($dbresult), true);
}

// ########## </Locationinfo> ###########

// ########## <Openingtime> ##########
/**
 * Gets the Opening time of the given locations.
 *
 * @param $idList Array list of locations
 * @return string Opening times JSON
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
		return '[]';
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
	return json_encode($returnValue);
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
	$weekarray = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
	foreach ($weekarray as $d) {
		$array = array();
		foreach ($openingtime as $opt) {
			foreach ($opt['days'] as $val) {
				if ($val == $d) {
					$arr = array();

					$openTime = explode(':', $opt['openingtime']);
					$arr['HourOpen'] = $openTime[0];
					$arr['MinutesOpen'] = $openTime[1];

					$closeTime = explode(':', $opt['closingtime']);
					$arr['HourClose'] = $closeTime[0];
					$arr['MinutesClose'] = $closeTime[1];

					$array[] = $arr;
				}
			}
			if (!empty($array)) {
				$result[$d] = $array;
			}
		}
	}
	return $result;
}

// ########## </Openingtime> ##########
/**
 * Gets the config of the location.
 *
 * @param $locationID ID of the location
 */
function getConfig($locationID)
{
	$dbresult = Database::queryFirst("SELECT l.locationname, li.config, li.serverlocationid, s.servertype FROM `locationinfo_locationconfig` AS li
		RIGHT JOIN `location` AS l ON l.locationid=li.locationid
		LEFT JOIN `locationinfo_coursebackend` AS s ON s.serverid=li.serverid
		WHERE l.locationid=:locationID", array('locationID' => $locationID));
	$config = array();

	if ($dbresult['locationname'] == null) {
		$config = array();
	} else {

		if ($dbresult['config'] == null) {
			defaultConfig($config);
		} else {
			$config = json_decode($dbresult['config'], true);
		}

		$config['room'] = $dbresult['locationname'];
		$date = getdate();
		$config['time'] = $date['year'] . "-" . $date['mon'] . "-" . $date['mday'] . " " . $date['hours'] . ":" . $date['minutes'] . ":" . $date['seconds'];
	}
	echo json_encode($config, true);
}

/**
 * Creates and returns a default config for room that didn't saved a config yet.
 *
 * @return Return a default config.
 */
function defaultConfig(&$config)
{
	$config['language'] = 'en';
	$config['mode'] = 1;
	$config['vertical'] = false;
	$config['eco'] = false;
	$config['scaledaysauto'] = true;
	$config['daystoshow'] = 7;
	$config['rotation'] = 0;
	$config['scale'] = 50;
	$config['switchtime'] = 20;
	$config['calupdate'] = 30;
	$config['roomupdate'] = 5;
	$config['configupdate'] = 180;

	return $config;
}

/**
 * Gets the pc states of the given locations.
 *
 * @param $idList Array list of the location ids.
 * @return string PC state JSON
 */
function getPcStates($idList)
{
	$pcStates = array();

	$locationInfoList = json_decode(getLocationInfo($idList), true);
	foreach ($locationInfoList as $locationInfo) {
		$result['id'] = $locationInfo['id'];
		$idle = 0;
		$occupied = 0;
		$off = 0;
		$broken = 0;

		foreach ($locationInfo['computer'] as $computer) {
			if ($computer['pcState'] == "IDLE") {
				$idle++;
			} elseif ($computer['pcState'] == "OCCUPIED") {
				$occupied++;
			} elseif ($computer['pcState'] == "OFF") {
				$off++;
			} elseif ($computer['pcState'] == "BROKEN") {
				$broken++;
			}
		}

		$result['idle'] = $idle;
		$result['occupied'] = $occupied;
		$result['off'] = $off;
		$result['broken'] = $broken;
		$pcStates[] = $result;
	}
	return json_encode($pcStates);
}

/**
 * Gets the location tree of the given locations.
 *
 * @param int[] $idList Array list of the locations.
 * @return string location tree data as JSON.
 */
function getLocationTree($idList)
{
	$locations = Location::getTree();

	$ret = findLocations($locations, $idList);
	return json_encode($ret);
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
 * @param $idList Array list with the location ids.
 * @return string Calendar JSON.
 */
function getCalendar($idList)
{
	$serverList = array();

	if (!empty($idList)) {
		// Build SQL query for multiple ids.
		$qs = '?' . str_repeat(',?', count($idList) - 1);
		$query = "SELECT l.locationid, l.serverid, l.serverlocationid, s.servertype, s.credentials
				FROM `locationinfo_locationconfig` AS l
				INNER JOIN locationinfo_coursebackend AS s ON (s.serverid = l.serverid)
				WHERE l.hidden = 0 AND l.locationid IN ($qs)
				ORDER BY s.servertype ASC";

		$dbquery = Database::simpleQuery($query, array_values($idList));

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
	return json_encode($resultArray);
}

// ########## </Calendar> ##########
