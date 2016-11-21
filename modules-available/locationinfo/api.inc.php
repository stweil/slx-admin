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
}

function getOpeningTimes($locationID) {
	$error = checkIfHidden($locationID);
	if ($error == true) {
		echo "ERROR";
		return;
	}
	$dbquery = Database::simpleQuery("SELECT openingtime FROM `location_info` WHERE locationid = :locationID", array('locationID' => $locationID));

	$result = array();
	while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
	  $dbresult = json_decode($dbdata['openingtime'], true);
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

						//$arr['HourOpen'] = $day['openingtime'][0] . $day['openingtime'][1];
						//$arr['MinutesOpen'] = $day['openingtime'][3] . $day['openingtime'][4];
						$closeTime = explode(':', $day['closingtime']);
						$arr['HourClose'] = $closeTime[0];
						$arr['MinutesClose'] = $closeTime[1];

						//$arr['HourClose'] = $day['closingtime'][0] . $day['closingtime'][1];
						//$arr['MinutesClose'] = $day['closingtime'][3] . $day['closingtime'][4];

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

	if ($error === false) {
		echo $pcs;
	} else {
		echo "ERROR";
	}
}

function getPcInfos($locationID, $coords) {
	$dbquery;

	if ($coords === '1') {
		$dbquery = Database::simpleQuery("SELECT machineuuid, position, logintime FROM `machine` WHERE locationid = :locationID" , array('locationID' => $locationID));
	} else {
		$dbquery = Database::simpleQuery("SELECT machineuuid, logintime FROM `machine` WHERE locationid = :locationID" , array('locationID' => $locationID));
	}

	$pcs = array();

	while($pc=$dbquery->fetch(PDO::FETCH_ASSOC)) {

			$computer = array();

			$computer['id'] = $pc['machineuuid'];

			if ($coords === '1') {
				$position = json_decode($pc['position'], true);
				$computer['x'] = $position['gridRow'];
				$computer['y'] = $position['gridCol'];
			}

			$computer['inUse'] = 0;

			if ($pc['logintime'] > 0) {
				$computer['inUse'] = 1;
			}

			$pcs[] = $computer;
	}

	$str = json_encode($pcs, true);

	return $str;
}
