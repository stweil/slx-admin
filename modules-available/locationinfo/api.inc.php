<?php

HandleParameters();

function HandleParameters() {

	$getAction = Request::get('action', 0, 'string');
	if ($getAction == "roominfo") {
		$getRoomID = Request::get('id', 0, 'int');
		$getCoords = Request::get('coords', 0, 'string');
		if (empty($getCoords)) {
			$getCoords = '0';
		}
		getRoomInfoJson($getRoomID, $getCoords);
	} elseif ($getAction == "openingtime") {
		$getRoomID = Request::get('id', 0, 'int');
		getOpeningTimes($getRoomID);
	} elseif ($getAction == "config") {
		$getRoomID = Request::get('id', 0, 'int');
		getConfig($getRoomID);
	} elseif ($getAction == "calendar") {
		$getRoomID = Request::get('id', 0, 'int');
		getCalendar($getRoomID);
	}
}

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
		echo updateCalendar($getRoomID, $serverid, $serverroomid);
	} else {
		echo $calendar;
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

function getConfig($locationID) {
	$dbquery = Database::simpleQuery("SELECT l.locationname, li.config FROM `location_info` AS li
		RIGHT JOIN `location` AS l ON l.locationid=li.locationid WHERE l.locationid=:locationID", array('locationID' => $locationID));

	$config = array();
	while($dbresult=$dbquery->fetch(PDO::FETCH_ASSOC)) {
		$config = json_decode($dbresult['config'], true);
		$config['room'] = $dbresult['locationname'];
		$date = getdate();
		$config['time'] = $date['year'] . "-" . $date['mon'] . "-" . $date['mday'] . " " . $date['hours'] . ":" . $date['minutes'] . ":" . $date['seconds'];
	}
	if (empty($config)) {
		echo json_encode(array());
	} else {
		echo json_encode($config);
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

function getOpeningTimesFromParent($locationID) {
	$dbquery = Database::simpleQuery("SELECT parentlocationid FROM `location` WHERE locationid = :locationID", array('locationID' => $locationID));
	$parentlocationid = 0;
	while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
		$parentlocationid = (int)$dbdata['parentlocationid'];
	}
	if ($parentlocationid == 0) {
		echo json_encode(array());
	}else {
		getOpeningTimes($parentlocationid);
	}
}

function getOpeningTimes($locationID) {
	$error = checkIfHidden($locationID);
	if ($error == true) {
		echo "ERROR";
		return;
	}
	$dbquery = Database::simpleQuery("SELECT openingtime FROM `location_info` WHERE locationid = :locationID", array('locationID' => $locationID));

	$result = array();
	$dbresult = array();

	while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
	  $dbresult = json_decode($dbdata['openingtime'], true);
	}

	if (count($dbresult) == 0) {
		getOpeningTimesFromParent($locationID);
		return;
	}

	$weekarray = array ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");

		foreach ($weekarray as $d) {
			$array = array();
			foreach ($dbresult as $day) {
				foreach($day['days'] as $val) {
					if ($val == $d) {
						$arr = array();

						$openTime = explode(':', $day['openingtime']);
						$arr['HourOpen'] = $openTime[0];
						$arr['MinutesOpen'] = $openTime[1];

						$closeTime = explode(':', $day['closingtime']);
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

	echo json_encode($result, true);
}

function getRoomInfoJson($locationID, $coords) {
	$error = checkIfHidden($locationID);

	$pcs = getPcInfos($locationID, $coords);

	if (empty($pcs)) {
		$error = true;
	}

	if ($error == true) {
		echo "ERROR";
	} else {
		echo $pcs;
	}
}

function getPcInfos($locationID, $coords) {
	if ($coords == '1') {
		$dbquery = Database::simpleQuery("SELECT machineuuid, position, logintime FROM `machine` WHERE locationid = :locationID" , array('locationID' => $locationID));
	} else {
		$dbquery = Database::simpleQuery("SELECT machineuuid, logintime FROM `machine` WHERE locationid = :locationID" , array('locationID' => $locationID));
	}

	$pcs = array();

	while($pc=$dbquery->fetch(PDO::FETCH_ASSOC)) {

			$computer = array();

			$computer['id'] = $pc['machineuuid'];

			if ($coords == '1') {
				$position = json_decode($pc['position'], true);
				$computer['x'] = $position['gridCol'];
				$computer['y'] = $position['gridRow'];
			}

			$computer['pcState'] = LocationInfo::getPcState((int)$pc['logintime'], (int)$pc['lastseen']);

			$pcs[] = $computer;
	}

	$str = json_encode($pcs, true);

	return $str;
}
function fetchNewTimeTable($locationID){
            //Get room information
            $dbquery1 = Database::simpleQuery("SELECT serverid, serverroomid FROM location_info WHERE locationid = :id", array('id' => $locationID));
            $dbd1=$dbquery1->fetch(PDO::FETCH_ASSOC);
            $serverID = $dbd1['serverid'];
            $roomID = $dbd1['serverroomid'];
            //Get login data for the server
            $dbquery2 = Database::simpleQuery("SELECT serverurl, servertype, login, passwd FROM `setting_location_info` WHERE serverid = :id", array('id' => $serverID));
            $dbd2=$dbquery2->fetch(PDO::FETCH_ASSOC);
            $url = $dbd2['serverurl'];
            $type = $dbd2['servetype'];
            $lname = $dbd2['login'];
            $passwd = $dbd2['passwd'];
            //Return json with dates
            if($type == 'HISinOne'){
                $array = file_get_contents($url . $roomID . '.json');
                $ttable = json_decode($array);
                $results = count($ttable);
                for ($r = 0; $r < $results; $r++){
                    unset($ttable[$r]->allDay);
                }
               
            }
            else{
                $ttable = "{}";
            }
             return json_encode($ttable);         
        }
