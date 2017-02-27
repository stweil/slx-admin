<?php

HandleParameters();

function HandleParameters() {

	$getAction = Request::get('action', 0, 'string');
	if ($getAction == "roominfo") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = getMultipleInformations($roomIDs);
		$getCoords = Request::get('coords', 0, 'string');
		if (empty($getCoords)) {
			$getCoords = '0';
		}
		echo getRoomInfo($array, $getCoords);
	} elseif ($getAction == "openingtime") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = getMultipleInformations($roomIDs);
		echo getOpeningTime($array);
	} elseif ($getAction == "config") {
		$getRoomID = Request::get('id', 0, 'int');
		getConfig($getRoomID);
	} elseif ($getAction == "pcstates") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = getMultipleInformations($roomIDs);
		echo getPcStates($array);
	} elseif ($getAction == "roomtree") {
		$roomIDS = Request::get('ids', 0, 'string');
		getRoomTree($roomIDS);
	//} elseif ($getAction == "calendar") {
//		$getRoomID = Request::get('id', 0, 'int');
//		echo getCalendar($getRoomID);
//	} elseif ($getAction == "calendars") {
//		$roomIDS = Request::get('ids', 0, 'string');
//		getCalendars($roomIDS);
	} elseif ($getAction == "test") {
		$roomIDs = Request::get('id', 0, 'string');
		$array = getMultipleInformations($roomIDs);
		getCalendar($array);
	}
}

function getMultipleInformations($roomids) {
	$idList = explode(',', $roomids);
	$filteredIdList = array_filter($idList, 'is_numeric');
	return $filteredIdList;
}

// ########## <Calendar> ###########
function getCalendar($idList) {

	//// Build SQL query for multiple ids.
	$query = "SELECT locationid, l.serverid, serverurl, s.servertype FROM `location_info` as l LEFT JOIN setting_location_info as s ON s.serverid WHERE locationid IN (";

	$query .= implode(",", $idList);

	$query .= ") AND l.serverid = s.serverid ORDER BY servertype ASC";

	$dbquery = Database::simpleQuery($query);

	while($dbresult=$dbquery->fetch(PDO::FETCH_ASSOC)) {

	}
	echo "TODO: Not implemented yet.";
}
// ########## </Calendar> ##########

// ########################################################################
function randomCalendarGenerator() {
	$randNum = rand(3, 7);

	$result = array();

	for ($i = 0; $i < $randNum; $i++) {
		$c = array();
		$c['title'] = getRandomWord();

		$randH = rand(8, 16);
		$rand2 = $randH + 2;
		$date = getdate();
		$mday = $date['mday'] + $i;
		$todays = $date['year'] . "-" . $date['month'] . "-" . $mday . " " . $randH . ":00:00";
		$c['start'] = $todays;
		$todaye = $date['year'] . "-" . $date['month'] . "-" . $mday . " " . $rand2 . ":00:00";
		$c['end'] = $todaye;
		$result[] = $c;
	}

	return json_encode($result);
}

function getRandomWord($len = 10) {
    $word = array_merge(range('a', 'z'), range('A', 'Z'));
    shuffle($word);
    return substr(implode($word), 0, $len);
}

/*
// TODO FILTER 2 weeks or some days only
function getCalendars($ids) {
	$idList = getMultipleInformations($ids);
	$calendars = array();

	foreach ($idList as $id) {
		$a['id'] = $id;
		$a['calendar'] = json_decode(getCalendar($id), true);
		$calendars[] = $a;
	}
	echo json_encode($calendars);
}

function getCalendar($getRoomID) {
	// TODO GET AND RETURN THE ACTUAL calendar
	//echo randomCalendarGenerator();

	$dbquery = Database::simpleQuery("SELECT calendar, lastcalendarupdate, serverid, serverroomid FROM `location_info` WHERE locationid=:locationID", array('locationID' => $getRoomID));

	$calendar;
	$lastupdate;
	$serverid;
	$serverroomid;
	while($dbresult=$dbquery->fetch(PDO::FETCH_ASSOC)) {
		$lastupdate = (int) $dbresult['lastcalendarupdate'];
		$calendar = $dbresult['calendar'];
		$serverid = $dbresult['serverid'];
		$serverroomid = $dbresult['serverroomid'];
	}

	$NOW = time();
	if ($lastupdate == 0 || $NOW - $lastupdate > 900) {
		return updateCalendar($getRoomID, $serverid, $serverroomid);
	} else {
		return $calendar;
	}
}

function updateCalendar($locationid, $serverid, $serverroomid) {
	// TODO CALL UpdateCalendar($serverid, $serverroomid);
	$result = randomCalendarGenerator();
	// ^ replace with the actual call

	// Save in db and update timestamp
	$NOW = time();
	Database::exec("UPDATE `location_info` Set calendar=:calendar, lastcalendarupdate=:now WHERE locationid=:id", array('id' => $locationid, 'calendar' => $result, 'now' => $NOW));

	return $result;
}
*/
// ########################################################################


function getPcStates($idList) {
	$pcStates = array();

	$roominfoList = json_decode(getRoomInfo($idList), true);
	foreach ($roominfoList as $roomInfo) {
		$result['id'] = $id;
		$idle = 0;
		$occupied = 0;
		$off = 0;
		$broken = 0;

		foreach ($roomInfo['computer'] as $computer) {
			if ($computer['pcState'] == 0) {
				$idle++;
			} elseif($computer['pcState'] == 1) {
				$occupied++;
			} elseif($computer['pcState'] == 2) {
				$off++;
			} elseif($computer['pcState'] == 3) {
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

function getRoomTree($ids) {
	$idList = getMultipleInformations($ids);
	$roomTree = array();
	foreach ($idList as $id) {
		$dbquery = Database::simpleQuery("SELECT locationname FROM `location` WHERE locationid=:locationID", array('locationID' => $id));
		$dbresult=$dbquery->fetch(PDO::FETCH_ASSOC);

		$a['id'] = $id;
		$a['name'] = $dbresult['locationname'];
		$a['childs'] = getChildsRecursive($id);
		$roomTree[] = $a;
	}
  // TODO FIlter recursive childs (delete doubles) (Filteere froeach when recursive child exists)
	echo json_encode($roomTree);
}

function getChildsRecursive($id) {
	$dbquery = Database::simpleQuery("SELECT locationid, locationname FROM `location` WHERE parentlocationid=:locationID", array('locationID' => $id));
	$array = array();
	$dbarray = array();

	while($dbresult=$dbquery->fetch(PDO::FETCH_ASSOC)) {
		$dbarray[] = $dbresult;
	}

	foreach ($dbarray as $db) {
		$i = $db['locationid'];
		$a['id'] = $i;
		$a['name'] = $db['locationname'];
		$a['childs'] = getChildsRecursive($i);
		$array[] = $a;
	}

	return $array;
}

function getConfig($locationID) {
		$dbquery = Database::simpleQuery("SELECT l.locationname, li.config, li.serverroomid, s.servertype, s.serverurl FROM `location_info` AS li
		RIGHT JOIN `location` AS l ON l.locationid=li.locationid
		LEFT JOIN `setting_location_info` AS s ON s.serverid=li.serverid
		WHERE l.locationid=:locationID", array('locationID' => $locationID));

	$config = array();
	$dbresult=$dbquery->fetch(PDO::FETCH_ASSOC);

	$config = json_decode($dbresult['config'], true);
	$config['room'] = $dbresult['locationname'];
	$date = getdate();
	$config['time'] = $date['year'] . "-" . $date['mon'] . "-" . $date['mday'] . " " . $date['hours'] . ":" . $date['minutes'] . ":" . $date['seconds'];

	if($dbresult['servertype'] === "Frontend") {
		$config['calendarqueryurl'] = $dbresult['serverurl'] . "/" . $dbresult['serverroomid'] . ".json";
	}

	if (empty($config)) {
		echo json_encode(array());
	} else {
		echo json_encode($config,  JSON_UNESCAPED_SLASHES);
	}
}

function checkIfHidden($locationID) {
	$dbquery = Database::simpleQuery("SELECT hidden FROM `location_info` WHERE locationid = :locationID", array('locationID' => $locationID));

	while($roominfo=$dbquery->fetch(PDO::FETCH_ASSOC)) {
		$hidden = $roominfo['hidden'];
		if ($hidden === '0') {
			return false;
		} else {
			return true;
		}
	}
	return false;
}

// ########## <Roominfo> ##########

function getRoomInfo($idList, $coords) {

	// Build SQL query for multiple ids.
	$query = "SELECT locationid, machineuuid, position, logintime, lastseen, lastboot FROM `machine` WHERE ";
	$or = false;
	foreach($idList as $id) {
		if (checkIfHidden($id)) {
			continue;
		}
		if ($or) {
			$query .= " OR ";
		}

		$query .= "locationid = " . $id;
		$or = true;
	}

	$query .= " ORDER BY locationid ASC";
	// Execute query.
	$dbquery = Database::simpleQuery($query);
	$dbresult = array();

	$currentlocationid = 0;

	$lastentry;
	$pclist = array();
	while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {

		// Add the data in the array if locationid changed
		if ($dbdata['locationid'] != $currentlocationid && $currentlocationid != 0) {
			$data['id'] = $currentlocationid;
			$data['computer'] = $pclist;
			$dbresult[] = $data;
			$pclist = array();
		}

		$pc['id'] = $dbdata['machineuuid'];

		// Add coordinates if bool = true.
		if ($coords == '1') {
			$position = json_decode($dbdata['position'], true);
			$pc['x'] = $position['gridCol'];
			$pc['y'] = $position['gridRow'];
		}

		$pc['pcState'] = LocationInfo::getPcState($dbdata);
		$pclist[] = $pc;

		// Save the last entry to add this at the end.
		$lastentry['id'] = $dbdata['locationid'];
		$lastentry['computer'] = $pclist;
		$currentlocationid = $dbdata['locationid'];
	}
	$dbresult[] = $lastentry;

	return json_encode($dbresult, true);
}
// ########## </Roominfo> ###########

// ########## <Openingtime> ##########

function getOpeningTime($idList) {

	// Build SQL Query for multiple ids.
	$query = "SELECT locationid, openingtime FROM `location_info` WHERE ";
	$or = false;
	foreach($idList as $id) {
		if($or) {
			$query .= " OR ";
		}

		$query .= "locationid = " . $id;

		$or = true;
	}

	// Execute query.
	$dbquery = Database::simpleQuery($query);
	$dbresult = array();
	$handledIds = array();
	while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
		$data['id'] = $dbdata['locationid'];
		$data['openingtime'] = json_decode($dbdata['openingtime'], true);
		$handledIds[] = $data['id'];
	  $dbresult[] = $data;
	}
	$finalArray = array();
	$idList = array_diff($idList, $handledIds);
	foreach ($idList as $id) {
		$data['id'] = $id;
		$data['openingtime'] = array();
		$dbresult[] = $data;
	}

	// Go through the db entrys [id] = id; [openingtime] = e.g. [{"days":["Saturday","Sunday"],"openingtime":"12:32","closingtime":"14:35"}]
	foreach($dbresult as $entry) {
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

// Format the openingtime in the frontend needed format.
function formatOpeningtime($openingtime) {
	$weekarray = array ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
	foreach ($weekarray as $d) {
		$array = array();
		foreach ($openingtime as $opt) {
			foreach($opt['days'] as $val) {
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
			if(!empty($array)) {
				$result[$d] = $array;
			}
		}
	}
	return $result;
}

function getOpeningTimesFromParent($locationID) {
	// Get parent location id.
	$dbquery = Database::simpleQuery("SELECT parentlocationid FROM `location` WHERE locationid = :locationID", array('locationID' => $locationID));
	$parentlocationid = 0;
	while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
		$parentlocationid = (int)$dbdata['parentlocationid'];
	}

	if ($parentlocationid == 0) {
		return createBasicClosingTime();
	} else {
		$dbquery = Database::simpleQuery("SELECT openingtime FROM `location_info` WHERE locationid = :locationID", array('locationID' => $parentlocationid));
		$dbresult = array();
		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$dbresult = json_decode($dbdata['openingtime'], true);
		}

		if (count($dbresult) == 0) {
			return getOpeningTimesFromParent($parentlocationid);
		} else {
			return formatOpeningtime($dbresult);
		}
	}
}

function createBasicClosingTime() {
	$weekarray = array ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
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
