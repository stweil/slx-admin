<?php

/*
 * vvv - API to Panel - vvv
 */

HandleParameters();

/**
 * Handles the API parameters.
 */
function HandleParameters()
{

	$get = Request::get('get', 0, 'string');
	$uuid = Request::get('uuid', false, 'string');
	$output = false;
	if ($get === "timestamp") {
		$output = array('ts' => getLastChangeTs($uuid));
	} elseif ($get === "machines") {
		$locationIds = LocationInfo::getLocationsOr404($uuid);
		$output = array();
		InfoPanel::appendMachineData($output, $locationIds, true);
		$output = array_values($output);
	} elseif ($get === "config") {
		$type = InfoPanel::getConfig($uuid, $output);
		if ($type === false) {
			http_response_code(404);
			die('Panel not found');
		}
	} elseif ($get === "pcstates") {
		$locationIds = LocationInfo::getLocationsOr404($uuid);
		$output = getPcStates($locationIds, $uuid);
	} elseif ($get === "locationtree") {
		$locationIds = LocationInfo::getLocationsOr404($uuid);
		$output = getLocationTree($locationIds);
	} elseif ($get === "calendar") {
		$locationIds = LocationInfo::getLocationsOr404($uuid);
		$output = getCalendar($locationIds);
	}
	if ($output !== false) {
		Header('Content-Type: application/json; charset=utf-8');
		echo json_encode($output);
	} else {
		http_response_code(404);
		echo 'Unknown get option';
	}
}

/**
 * Get last config modification timestamp for given panel.
 * This is incomplete however, as it wouldn't react to the
 * linked room plan being edited, or added/removed PCs
 * etc. So the advice would simply be "if you want the
 * panel to reload automatically, hit the edit button
 * and click save". Might even add a shortcut
 * reload-button to the list of panels at some point.
 *
 * @param string $paneluuid panels uuid
 * @return int UNIX_TIMESTAMP
 */
function getLastChangeTs($paneluuid)
{
	$panel = Database::queryFirst('SELECT lastchange, locationids FROM locationinfo_panel WHERE paneluuid = :paneluuid',
		compact('paneluuid'));
	if ($panel === false) {
		http_response_code(404);
		die('Panel not found');
	}
	$lastChange = array((int)$panel['lastchange']);
	if (!empty($panel['locationids'])) {
		$res = Database::simpleQuery('SELECT lastchange FROM locationinfo_locationconfig
				WHERE locationid IN (:locs)', array('locs' => explode(',', $panel['locationids'])));
		while (($lc = $res->fetchColumn()) !== false) {
			$lastChange[] = (int)$lc;
		}
	}
	return max($lastChange);
}

/**
 * Gets the pc states of the given locations.
 *
 * @param int[] $idList list of the location ids.
 * @return array aggregated PC states
 */
function getPcStates($idList, $paneluuid)
{
	$pcStates = array();
	foreach ($idList as $id) {
		$pcStates[$id] = array(
			'id' => $id,
			'idle' => 0,
			'occupied' => 0,
			'offline' => 0,
			'broken' => 0,
			'standby' => 0,
		);
	}

	$locationInfoList = array();
	InfoPanel::appendMachineData($locationInfoList, $idList, true);

	$panel = Database::queryFirst('SELECT paneluuid, panelconfig FROM locationinfo_panel WHERE paneluuid = :paneluuid',
		compact('paneluuid'));
	$config = json_decode($panel['panelconfig'], true);

	foreach ($locationInfoList as $locationInfo) {
		$id = $locationInfo['id'];
		foreach ($locationInfo['machines'] as $pc) {
			$key = strtolower($pc['pcState']);
			if (isset($pcStates[$id][$key])) {
				if ($config['roomplanner']) {
					if (isset($pc['x']) && isset($pc['y'])) {
						$pcStates[$id][$key]++;
					}
				} else {
					$pcStates[$id][$key]++;
				}
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
			EventLog::warning('Cannot fetch schedule for location (' . implode(', ', $server['idlist']) . ')'
				. ': Backend type ' . $server['type'] . ' unknown. Disabling location.');
			Database::exec("UPDATE locationinfo_locationconfig SET serverid = NULL WHERE locationid IN (:lid)",
				array('lid' => $server['idlist']));
			continue;
		}
		$credentialsOk = $serverInstance->setCredentials($serverid, $server['credentials']);

		if ($credentialsOk) {
			$calendarFromBackend = $serverInstance->fetchSchedule($server['idlist']);
		} else {
			$calendarFromBackend = array();
		}

		LocationInfo::setServerError($serverid, $serverInstance->getErrors());

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
