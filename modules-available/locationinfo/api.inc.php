<?php

if (Request::get('redirect', false, 'int') !== false) {
	// Redirect to actual panel from uuid
	$uuid = Request::get('uuid', false, 'string');
	if ($uuid === false) {
		http_response_code(400);
		die('Missing uuid parameter');
	}
	$row = Database::queryFirst('SELECT paneltype, panelconfig FROM locationinfo_panel WHERE paneluuid = :uuid', compact('uuid'));
	if ($row === false) {
		http_response_code(404);
		die('Panel not found');
	}
	if ($row['paneltype'] === 'DEFAULT') {
		Util::redirect(dirname($_SERVER['SCRIPT_NAME']) . '/modules/locationinfo/frontend/doorsign.html?uuid=' . $uuid);
	} elseif ($row['paneltype'] === 'SUMMARY') {
		Util::redirect(dirname($_SERVER['SCRIPT_NAME']) . '/modules/locationinfo/frontend/overview.html?uuid=' . $uuid);
	} elseif ($row['paneltype'] === 'URL') {
		$data = json_decode($row['panelconfig'], true);
		if (!$data || !isset($data['url'])) {
			http_response_code('500');
			die('Panel config corrupted on server');
		}
		Util::redirect($data['url']);
	}
	http_response_code('500');
	die('Panel has invalid type "' . $row['paneltype'] . '"');
}

/*
 * vvv - API to Panel - vvv
 */

HandleParameters();

/**
 * Handles the API paramenters.
 */
function HandleParameters()
{

	$get = Request::get('get', 0, 'string');
	$uuid = Request::get('uuid', false, 'string');
	$output = false;
	if ($get === "timestamp") {
		$output = array('ts' => getLastChangeTs($uuid));
	} elseif ($get === "machines") {
		$locationIds = getLocationsOr404($uuid);
		$output = array();
		InfoPanel::appendMachineData($output, $locationIds, false);
		$output = array_values($output);
	} elseif ($get === "config") {
		$type = InfoPanel::getConfig($uuid, $output);
		if ($type === false) {
			http_response_code(404);
			die('Panel not found');
		}
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
		Header('Content-Type: application/json; charset=utf-8');
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

/**
 * Get last config modification timestamp for given panel.
 * This was planned to be smart and check the involved locations,
 * even going up the location tree if the opening time schedule
 * is inherited, but this would still be incomplete by design, as
 * it wouldn't react to the linked room plan being considered
 * for changes, or added/removed PCs etc. So rather than giving
 * an incomplete "clever" design for detecting changes, we only
 * consider direct editing of the panel now. So the advice would
 * simply be "if you want the panel to reload automatically, hit
 * the edit button and click save". Might even add a shortcut
 * reload-button to the list of panels at some point.
 *
 * @param string $paneluuid panels uuid
 * @return int UNIX_TIMESTAMP
 */
function getLastChangeTs($paneluuid)
{
	$panel = Database::queryFirst('SELECT lastchange FROM locationinfo_panel WHERE paneluuid = :paneluuid',
		compact('paneluuid'));
	if ($panel === false) {
		http_response_code(404);
		die('Panel not found');
	}
	return (int)$panel['lastchange'];
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
	InfoPanel::appendMachineData($locationInfoList, $idList);
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
