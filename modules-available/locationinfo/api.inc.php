<?php

HandleParameters();

function HandleParameters() {
	$getAction = $_GET['action'];

	if ($getAction == "roominfo") {
		$getRoomID = $_GET['id'];
		$getCoords = $_GET['coords'];

		if (empty($getCoords)) {
			$getCoords = '0';
		}
		getRoomInfoJson($getRoomID, $getCoords);
	}
}

function getRoomInfoJson($locationID, $coords) {
	$error = false;

	$dbquery = Database::simpleQuery("SELECT hidden FROM `locationinfo` WHERE locationid = $locationID");

	while($roominfo=$dbquery->fetch(PDO::FETCH_ASSOC)) {
		$hidden = $roominfo['hidden'];
		if ($hidden === '0') {
			$error = false;
		} else {
			$error = true;
		}
	}
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
		$dbquery = Database::simpleQuery("SELECT machineuuid, position, logintime FROM `machine` WHERE locationid = $locationID");
	} else {
		$dbquery = Database::simpleQuery("SELECT machineuuid, logintime FROM `machine` WHERE locationid = $locationID");
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

?>
