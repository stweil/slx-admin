<?php

HandleParameters();

/**
 * Handles the API paramenters.
 */
function HandleParameters()
{

	$getAction = Request::get('action', 0, 'string');
	if ($getAction == "roominfo") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = filterIdList($roomIDs);
		$getCoords = Request::get('coords', 0, 'string');
		if (empty($getCoords)) {
			$getCoords = '0';
		}
		echo getRoomInfo($array, $getCoords);
	} elseif ($getAction == "openingtime") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = filterIdList($roomIDs);
		echo getOpeningTime($array);
	} elseif ($getAction == "config") {
		$getRoomID = Request::get('id', 0, 'int');
		getConfig($getRoomID);
	} elseif ($getAction == "pcstates") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = filterIdList($roomIDs);
		echo getPcStates($array);
	} elseif ($getAction == "roomtree") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = filterIdList($roomIDs);
		echo getRoomTree($array);
	} elseif ($getAction == "calendar") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = filterIdList($roomIDs);
		echo getCalendar($array);
	}
}

/**
 * Filters the id list. Removes Double / non-int / hidden rooms.
 *
 * @param $roomids Array of the room ids.
 * @return array The filtered array of the room ids.
 */
function filterIdList($roomids)
{
	$idList = explode(',', $roomids);
	$filteredIdList = array_filter($idList, 'is_numeric');
	$filteredIdList = array_unique($filteredIdList);
	$filteredIdList = filterHiddenRoom($filteredIdList);

	return $filteredIdList;
}

/**
 * Filters the hidden rooms from an array.
 *
 * @param $idArray Id list
 * @return array Filtered id list
 */
function filterHiddenRoom($idArray)
{
	$filteredArray = array();
	if (!empty($idArray)) {
		$query = "SELECT locationid, hidden FROM `location_info` WHERE locationid IN (";
		$query .= implode(",", $idArray);
		$query .= ")";

		$dbquery = Database::simpleQuery($query);

		while ($dbresult = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			if ($dbresult['hidden'] == false) {
				$filteredArray[] = $dbresult['locationid'];
			}
		}
	}

	return $filteredArray;
}

// ########## <Roominfo> ##########
/**
 * Gets the room info of the given rooms.
 *
 * @param $idList Array list of ids.
 * @param bool $coords Defines if coords should be included or not.
 * @return string Roominfo JSON
 */
function getRoomInfo($idList, $coords = false)
{

	$coordinates = (string)$coords;
	$dbresult = array();

	if (!empty($idList)) {
		// Build SQL Query for multiple ids.
		$query = "SELECT m.locationid, machineuuid, position, logintime, lastseen, lastboot FROM `machine` AS m LEFT JOIN location_info AS l ON l.locationid = m.locationid WHERE l.hidden = 0 AND m.locationid IN (";

		$query .= implode(",", $idList);
		$query .= ")";

		$query .= " ORDER BY m.locationid ASC";

		// Execute query.
		$dbquery = Database::simpleQuery($query);

		$currentlocationid = 0;

		$pclist = array();

		// Fetch db data.
		while ($dbdata = $dbquery->fetch(PDO::FETCH_ASSOC)) {

			// Set the id if the locationid changed.
			if (!isset($dbresult[$dbdata['locationid']])) {
				$dbresult[$dbdata['locationid']] = array('id' => $dbdata['locationid'], 'computer' => array());
			}

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

// ########## </Roominfo> ###########

// ########## <Openingtime> ##########
/**
 * Gets the Opening time of the given locations.
 *
 * @param $idList Array list of locations
 * @return string Opening times JSON
 */
function getOpeningTime($idList)
{
	$dbresult = array();
	$finalArray = array();

	if (!empty($idList)) {
		// Build SQL Query for multiple ids.
		$query = "SELECT locationid, openingtime FROM `location_info` WHERE locationid IN (";
		$query .= implode(",", $idList);
		$query .= ")";

		// Execute query.
		$dbquery = Database::simpleQuery($query);
		$handledIds = array();
		while ($dbdata = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			$data['id'] = $dbdata['locationid'];
			$data['openingtime'] = json_decode($dbdata['openingtime'], true);
			$handledIds[] = $data['id'];
			$dbresult[] = $data;
		}


		$idList = array_diff($idList, $handledIds);
		foreach ($idList as $id) {
			$data['id'] = $id;
			$data['openingtime'] = array();
			$dbresult[] = $data;
		}
	}

	// Go through the db entrys [id] = id; [openingtime] = e.g. [{"days":["Saturday","Sunday"],"openingtime":"12:32","closingtime":"14:35"}]
	foreach ($dbresult as $entry) {
		$tmp = array();
		// Get the parents time if there is no openingtime defined.
		if (count($entry['openingtime']) == 0) {
			//$tmp = getOpeningTimesFromParent($entry['id']);
			$tmp['id'] = $entry['id'];
			$tmp['openingtime'] = getOpeningTimesFromParent($entry['id']);
		} else {
			$tmp['id'] = $entry['id'];
			$tmp['openingtime'] = formatOpeningtime($entry['openingtime']);
		}
		$finalArray[] = $tmp;
	}
	return json_encode($finalArray, true);
}

/**
 * Format the openingtime in the frontend needed format.
 *
 * @param $openingtime The opening time in the db saved format.
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

/**
 * Recursively gets the opening time from the parent location.
 *
 * @param $locationID Id of the location you want to get the parent opening time.
 * @return array|mixed The opening time from the parent location.
 */
function getOpeningTimesFromParent($locationID)
{
	// Get parent location id.
	$dbquery = Database::queryFirst("SELECT parentlocationid FROM `location` WHERE locationid = :locationID", array('locationID' => $locationID));
	$parentlocationid = 0;
	$parentlocationid = (int)$dbquery['parentlocationid'];

	if ($parentlocationid == 0) {
		return createBasicClosingTime();
	} else {
		$dbquery = Database::queryFirst("SELECT openingtime FROM `location_info` WHERE locationid = :locationID", array('locationID' => $parentlocationid));
		$dbresult = json_decode($dbquery['openingtime'], true);

		if (count($dbresult) == 0) {
			return getOpeningTimesFromParent($parentlocationid);
		} else {
			return formatOpeningtime($dbresult);
		}
	}
}

/**
 * Creates the basic opening time where everything is closed.
 *
 * @return array Basic opening time.
 */
function createBasicClosingTime()
{
	$weekarray = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
	$array = array();
	foreach ($weekarray as $d) {
		$a = array();
		$arr['HourOpen'] = '00';
		$arr['MinutesOpen'] = '00';

		$arr['HourClose'] = '23';
		$arr['MinutesClose'] = '59';
		$a[] = $arr;
		$array[$d] = $a;
	}
	return $array;
}

// ########## </Openingtime> ##########
/**
 * Gets the config of the location.
 *
 * @param $locationID ID of the location
 */
function getConfig($locationID)
{
	$dbresult = Database::queryFirst("SELECT l.locationname, li.config, li.serverroomid, s.servertype, s.serverurl FROM `location_info` AS li
		RIGHT JOIN `location` AS l ON l.locationid=li.locationid
		LEFT JOIN `setting_location_info` AS s ON s.serverid=li.serverid
		WHERE l.locationid=:locationID", array('locationID' => $locationID));
	$config = array();

	$config = json_decode($dbresult['config'], true);
	$config['room'] = $dbresult['locationname'];
	$date = getdate();
	$config['time'] = $date['year'] . "-" . $date['mon'] . "-" . $date['mday'] . " " . $date['hours'] . ":" . $date['minutes'] . ":" . $date['seconds'];

	if ($dbresult['servertype'] === "Frontend") {
		$config['calendarqueryurl'] = $dbresult['serverurl'] . "/" . $dbresult['serverroomid'] . ".json";
	}

	if (empty($config)) {
		echo json_encode(array());
	} else {
		echo json_encode($config, JSON_UNESCAPED_SLASHES);
	}
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

	$roominfoList = json_decode(getRoomInfo($idList), true);
	foreach ($roominfoList as $roomInfo) {
		$result['id'] = $roomInfo['id'];
		$idle = 0;
		$occupied = 0;
		$off = 0;
		$broken = 0;

		foreach ($roomInfo['computer'] as $computer) {
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
 * Gets the room tree of the given locations.
 *
 * @param $idList Array list of the locations.
 * @return string Room tree JSON.
 */
function getRoomTree($idList)
{
	$roomTree = array();
	$filteredIdList = array();
	foreach ($idList as $id) {
		$dbresult = Database::queryFirst("SELECT locationname FROM `location` WHERE locationid=:locationID", array('locationID' => $id));

		if (!in_array($id, $filteredIdList)) {
			$a['id'] = $id;
			$a['name'] = $dbresult['locationname'];
			$filteredIdList[] = $id;
			$a['childs'] = getChildsRecursive($id, $filteredIdList);
			$roomTree[] = $a;
		}
	}

	return json_encode($roomTree);
}

/**
 * Recursively gets the Childs of a location.
 *
 * @param $id Location id you want all childs from.
 * @param $filteredIdList Pointer to the filtered list to avoid double ids.
 * @return array List with all the Childs.
 */
function getChildsRecursive($id, &$filteredIdList)
{
	$dbquery = Database::simpleQuery("SELECT locationid, locationname FROM `location` WHERE parentlocationid=:locationID", array('locationID' => $id));
	$array = array();
	$dbarray = array();

	while ($dbresult = $dbquery->fetch(PDO::FETCH_ASSOC)) {
		$dbarray[] = $dbresult;
	}
	foreach ($dbarray as $db) {
		$i = $db['locationid'];

		if (!in_array($i, $filteredIdList)) {
			$a['id'] = $i;
			$a['name'] = $db['locationname'];
			$filteredIdList[] = $i;
			$a['childs'] = getChildsRecursive($i, $filteredIdList);
			$array[] = $a;
		}

	}

	return $array;
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
		$query = "SELECT locationid, l.serverid AS serverid, serverurl, servertype, credentials FROM `location_info` AS l LEFT JOIN setting_location_info AS s ON s.serverid = l.serverid WHERE locationid IN (";

		$query .= implode(",", $idList);

		$query .= ") AND l.serverid = s.serverid ORDER BY servertype ASC, locationid ASC";

		$dbquery = Database::simpleQuery($query);

		$first = true;
		$lastservertype = "";
		while ($dbresult = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($serverList[$dbresult['serverid']])) {
				$serverList[$dbresult['serverid']] = array('credentials' => json_decode($dbresult['credentials'], true), 'url' => $dbresult['serverurl'], 'type' => $dbresult['servertype'], 'idlist' => array());
			}
			$serverList[$dbresult['serverid']]['idlist'][] = $dbresult['locationid'];
		}
	}

	$resultarray = array();
	foreach ($serverList as $serverid => $server) {
		$serverInstance = CourseBackend::getInstance($server['type']);
		$setCred = $serverInstance->setCredentials($server['credentials'], $server['url'], $serverid);

		$calendarFromBackend = array();
		if ($setCred) {
			$calendarFromBackend = $serverInstance->fetchSchedule($server['idlist']);
		}

		$formattedArray = array();
		if ($calendarFromBackend === false || $setCred === false) {
			$error['timestamp'] = time();
			$error['error'] = $serverInstance->getError();
			Database::exec("UPDATE `setting_location_info` SET error=:error WHERE serverid=:id", array('id' => $serverid, 'error' => json_encode($error, true)));
		} else {
			Database::exec("UPDATE `setting_location_info` SET error=NULL WHERE serverid=:id", array('id' => $serverid));
		}
		if (is_array($calendarFromBackend)) {
			foreach ($calendarFromBackend as $key => $value) {
				$y['id'] = $key;
				$y['calendar'] = $value;
				$formattedArray[] = $y;
			}
			$resultarray = array_merge($resultarray, $formattedArray);
		}
	}
	return json_encode($resultarray, true);
}

// ########## </Calendar> ##########
