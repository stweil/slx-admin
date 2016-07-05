<?php

class Page_Locations extends Page
{

	private $action;
	
	/*
	 * Action handling
	 */

	protected function doPreprocess()
	{
		User::load();
		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}
		$this->action = Request::post('action');
		if ($this->action === 'updatelocation') {
			$this->updateLocation();
		} elseif ($this->action === 'addlocations') {
			$this->addLocations();
		} elseif ($this->action === 'updatesubnets') {
			$this->updateSubnets();
		}
	}

	private function updateSubnets()
	{
		$count = 0;
		$starts = Request::post('startaddr', false);
		$ends = Request::post('endaddr', false);
		$locs = Request::post('location', false);
		if (!is_array($starts) || !is_array($ends) || !is_array($locs)) {
			Message::addError('main.empty-field');
			Util::redirect('?do=Locations');
		}
		$existingLocs = Location::getLocationsAssoc();
		$stmt = Database::prepare("UPDATE subnet SET startaddr = :startLong, endaddr = :endLong, locationid = :loc WHERE subnetid = :subnetid");
		foreach ($starts as $subnetid => $start) {
			if (!isset($ends[$subnetid]) || !isset($locs[$subnetid]))
				continue;
			$loc = (int)$locs[$subnetid];
			$end = $ends[$subnetid];
			if (!isset($existingLocs[$loc])) {
				Message::addError('main.value-invalid', 'locationid', $loc);
				continue;
			}
			$range = $this->rangeToLongVerbose($start, $end);
			if ($range === false)
				continue;
			list($startLong, $endLong) = $range;
			if ($stmt->execute(compact('startLong', 'endLong', 'loc', 'subnetid'))) {
				$count += $stmt->rowCount();
			}
		}
		Message::addSuccess('subnets-updated', $count);
		Util::redirect('?do=Locations');
	}
	
	private function addLocations()
	{
		$names = Request::post('newlocation', false);
		$parents = Request::post('newparent', false);
		if (!is_array($names) || !is_array($parents)) {
			Message::addError('main.empty-field');
			Util::redirect('?do=Locations');
		}
		$locs = Location::getLocations();
		$count = 0;
		foreach ($names as $idx => $name) {
			$name = trim($name);
			if (empty($name)) continue;
			$parent = isset($parents[$idx]) ? (int)$parents[$idx] : 0;
			if ($parent !== 0) {
				$ok = false;
				foreach ($locs as $loc) {
					if ($loc['locationid'] == $parent) {
						$ok = true;
					}
				}
				if (!$ok) {
					Message::addWarning('main.value-invalid', 'parentlocationid', $parent);
					continue;
				}
			}
			Database::exec("INSERT INTO location (parentlocationid, locationname)"
				. " VALUES (:parent, :name)", array(
					'parent' => $parent,
					'name' => $name
			));
			$count++;
		}
		Message::addSuccess('added-x-entries', $count);
		Util::redirect('?do=Locations');
	}
	
	private function updateLocation()
	{
		$locationId = Request::post('locationid', false, 'integer');
		$del = Request::post('deletelocation', false, 'integer');
		if ($locationId === false) {
			Message::addError('parameter-missing', 'locationid');
			Util::redirect('?do=Locations');
		}
		$location = Database::queryFirst('SELECT locationid, parentlocationid, locationname FROM location'
			. ' WHERE locationid = :lid', array('lid' => $locationId));
		if ($location === false) {
			Message::addError('main.value-invalid', 'locationid', $locationId);
			Util::redirect('?do=Locations');
		}
		// Delete location?
		if ($locationId === $del) {
			$this->deleteLocation($location);
		}
		// Update subnets
		$this->updateLocationSubnets();
		// Insert subnets
		$this->addNewLocationSubnets($location);
		// Update location!
		$this->updateLocationData($location);
		Util::redirect('?do=Locations');
	}
	
	private function deleteLocation($location)
	{
		$locationId = (int)$location['locationid'];
		$ids = $locationId;
		if (Request::post('recursive', false) === 'on') {
			$rows = Location::queryLocations();
			$rows = Location::buildTree($rows, $locationId);
			$rows = Location::extractIds($rows);
			if (!empty($rows)) {
				$ids .= ',' . implode(',', $rows);
			}
		}
		$subs = Database::exec("DELETE FROM subnet WHERE locationid IN ($ids)");
		$locs = Database::exec("DELETE FROM location WHERE locationid IN ($ids)");
		Database::exec('UPDATE location SET parentlocationid = :newparent WHERE parentlocationid = :oldparent', array(
			'newparent' => $location['parentlocationid'],
			'oldparent' => $location['locationid']
		));
		Message::addSuccess('location-deleted', $locs, $subs);
		Util::redirect('?do=Locations');
	}
	
	private function updateLocationData($location)
	{
		$locationId = (int)$location['locationid'];
		$newParent = Request::post('parentlocationid', false, 'integer');
		$newName = Request::post('locationname', false, 'string');
		if ($newName === false || preg_match('/^\s*$/', $newName)) {
			if ($newName !== false) {
				Message::addWarning('main.value-invalid', 'location name', $newName);
			}
			$newName = $location['locationname'];
		}
		if ($newParent === false) {
			$newParent = $location['parentlocationid'];
		} else if ($newParent !== 0) {
			$rows = Location::queryLocations();
			$all = Location::extractIds(Location::buildTree($rows));
			if (!in_array($newParent, $all) || $newParent === $locationId) {
				Message::addWarning('main.value-invalid', 'parent', $newParent);
				$newParent = $location['parentlocationid'];
			} else {
				$rows = Location::extractIds(Location::buildTree($rows, $locationId));
				if (in_array($newParent, $rows)) {
					Message::addWarning('main.value-invalid', 'parent', $newParent);
					$newParent = $location['parentlocationid'];
				}
			}
		}
		$ret = Database::exec('UPDATE location SET parentlocationid = :parent, locationname = :name'
			. ' WHERE locationid = :lid', array(
				'lid' => $locationId,
				'parent' => $newParent,
				'name' => $newName
		));
		if ($ret > 0) {
			Message::addSuccess('location-updated', $newName);
		}
	}

	private function updateLocationSubnets()
	{
		// Deletion first
		$dels = Request::post('deletesubnet', false);
		if (is_array($dels)) {
			$count = 0;
			$stmt = Database::prepare('DELETE FROM subnet WHERE subnetid = :id');
			foreach ($dels as $key => $value) {
				if (!is_numeric($key) || $value !== 'on') continue;
				if ($stmt->execute(array('id' => $key))) {
					$count += $stmt->rowCount();
				}
			}
			if ($count > 0) {
				Message::addInfo('subnets-deleted', $count);
			}
		}
		// Now actual updates
		$starts = Request::post('startaddr', false);
		$ends = Request::post('endaddr', false);
		if (!is_array($starts) || !is_array($ends)) {
			return;
		}
		$count = 0;
		$stmt = Database::prepare('UPDATE subnet SET startaddr = :start, endaddr = :end'
			. ' WHERE subnetid = :id');
		foreach ($starts as $key => $start) {
			if (!isset($ends[$key]) || !is_numeric($key)) continue;
			$end = $ends[$key];
			$range = $this->rangeToLongVerbose($start, $end);
			if ($range === false)
				continue;
			list($startLong, $endLong) = $range;
			if ($stmt->execute(array('id' => $key, 'start' => $startLong, 'end' => $endLong))) {
				$count += $stmt->rowCount();
			}
		}
		if ($count > 0) {
			Message::addInfo('subnets-updated', $count);
		}
	}
	
	private function addNewLocationSubnets($location)
	{
		$locationId = (int)$location['locationid'];
		$starts = Request::post('newstartaddr', false);
		$ends = Request::post('newendaddr', false);
		if (!is_array($starts) || !is_array($ends)) {
			return;
		}
		$count = 0;
		$stmt = Database::prepare('INSERT INTO subnet SET startaddr = :start, endaddr = :end, locationid = :location');
		foreach ($starts as $key => $start) {
			if (!isset($ends[$key]) || !is_numeric($key)) continue;
			$end = $ends[$key];
			list($startLong, $endLong) = $this->rangeToLong($start, $end);
			if ($startLong === false) {
				Message::addWarning('main.value-invalid', 'new start addr', $start);
			}
			if ($endLong === false) {
				Message::addWarning('main.value-invalid', 'new end addr', $start);
			}
			if ($startLong === false || $endLong === false) continue;
			if ($startLong > $endLong) {
				Message::addWarning('main.value-invalid', 'range', $start . ' - ' . $end);
				continue;
			}
			if ($stmt->execute(array('location' => $locationId, 'start' => $startLong, 'end' => $endLong))) {
				$count += $stmt->rowCount();
			}
		}
		if ($count > 0) {
			Message::addInfo('subnets-created', $count);
		}
	}
		
	/*
	 * Rendering normal pages
	 */

	protected function doRender()
	{
		$getAction = Request::get('action');
		if (empty($getAction)) {
			// Until we have a main landing page?
			Util::redirect('?do=Locations&action=showlocations');
		}
		if ($getAction === 'showsubnets') {
			$res = Database::simpleQuery("SELECT subnetid, startaddr, endaddr, locationid FROM subnet");
			$rows = array();
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$row['startaddr'] = long2ip($row['startaddr']);
				$row['endaddr'] = long2ip($row['endaddr']);
				$row['locations'] = Location::getLocations($row['locationid']);
				$rows[] = $row;
			}
			Render::addTemplate('subnets', array('list' => $rows));
		} elseif ($getAction === 'showlocations') {
			$this->showLocationList();
		}
	}

	private function showLocationList()
	{
		$overlapSelf = $overlapOther = true;
		$subnets = Location::getSubnetsByLocation($overlapSelf, $overlapOther);
		$locs = Location::getLocations(0, 0, false, true);
		// Statistics: Count machines for each subnet
		$unassigned = false;
		if (Module::get('statistics') !== false) {
			foreach ($locs as &$location) {
				$lid = (int)$location['locationid'];
				if (!isset($subnets[$lid]))
					continue;
				$loc =& $subnets[$lid];
				if (empty($loc['subnets'])) {
					$query = "SELECT Count(*) AS cnt FROM machine WHERE locationid = :locationid";
				} else {
					$query = "SELECT Count(*) AS cnt FROM machine WHERE locationid = :locationid OR (locationid IS NULL AND (0";
					foreach ($loc['subnets'] as $sub) {
						$query .= ' OR INET_ATON(clientip) BETWEEN ' . $sub['startaddr'] . ' AND ' . $sub['endaddr'];
					}
					$query .= '))';
				}
				$ret = Database::queryFirst($query, array('locationid' => $lid));
				$location['clientCount'] = $ret['cnt'];
			}
			$res = Database::queryFirst("SELECT Count(*) AS cnt FROM machine m"
				. " LEFT JOIN subnet s ON (INET_ATON(m.clientip) BETWEEN s.startaddr AND s.endaddr)"
				. " WHERE m.locationid IS NULL AND s.locationid IS NULL");
			$unassigned = $res['cnt'];
		}
		unset($loc, $location);
		// Show currently active sysconfig for each location
		$defaultConfig = false;
		if (Module::isAvailable('sysconfig')) {
			$confs = SysConfig::getAll();
			foreach ($confs as $conf) {
				$confLocs = explode(',', $conf['locs']);
				foreach ($confLocs as $loc) {
					settype($loc, 'int');
					if ($loc === 0) {
						$defaultConfig = $conf['title'];
					}
					if (!isset($locs[$loc]))
						continue;
					$locs[$loc] += array('configName' => $conf['title'], 'configClass' => 'slx-bold');
				}
			}
			$depth = array();
			foreach ($locs as &$loc) {
				$d = $loc['depth'];
				if (!isset($loc['configName'])) {
					// Has no explicit config assignment
					if ($d === 0) {
						$loc['configName'] = $defaultConfig;
					} else {
						$loc['configName'] = $depth[$d - 1];
					}
					$loc['configClass'] = 'gray';
				}
				$depth[$d] = $loc['configName'];
				unset($depth[$d + 1]);
			}
			unset($loc);
		}
		// Count overridden config vars
		if (Module::get('baseconfig') !== false) {
			$res = Database::simpleQuery("SELECT locationid, Count(*) AS cnt FROM `setting_location` GROUP BY locationid");
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$lid = (int)$row['locationid'];
				if (isset($locs[$lid])) {
					$locs[$lid]['overriddenVars'] = $row['cnt'];
				}
			}
		}
		// Output
		Render::addTemplate('locations', array(
			'list' => array_values($locs),
			'havestatistics' => Module::get('statistics') !== false,
			'havebaseconfig' => Module::get('baseconfig') !== false,
			'havesysconfig' => Module::get('sysconfig') !== false,
			'overlapSelf' => $overlapSelf,
			'overlapOther' => $overlapOther,
			'haveOverlapSelf' => !empty($overlapSelf),
			'haveOverlapOther' => !empty($overlapOther),
			'unassignedCount' => $unassigned,
			'defaultConfig' => $defaultConfig,
		));
	}

	/*
	 * Ajax
	 */

	protected function doAjax()
	{
		User::load();
		if (!User::isLoggedIn()) {
			die('Unauthorized');
		}
		$action = Request::any('action');
		if ($action === 'showlocation') {
			$this->ajaxShowLocation();
		}
	}
	
	private function ajaxShowLocation()
	{
		$locationId = Request::any('locationid', 0, 'integer');
		$loc = Database::queryFirst('SELECT locationid, parentlocationid, locationname FROM location WHERE locationid = :lid',
			array('lid' => $locationId));
		if ($loc === false) {
			die('Unknown locationid');
		}
		$res = Database::simpleQuery("SELECT subnetid, startaddr, endaddr FROM subnet WHERE locationid = :lid",
			array('lid' => $locationId));
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['startaddr'] = long2ip($row['startaddr']);
			$row['endaddr'] = long2ip($row['endaddr']);
			$rows[] = $row;
		}
		$data = array(
			'locationid' => $loc['locationid'],
			'locationname' => $loc['locationname'],
			'list' => $rows,
			'parents' => Location::getLocations($loc['parentlocationid'], $locationId, true)
		);
		if (Module::get('dozmod') !== false) {
			$lectures = Database::queryFirst('SELECT Count(*) AS cnt FROM sat.lecture l '
				. ' INNER JOIN sat.lecture_x_location ll ON (l.lectureid = ll.lectureid AND ll.locationid = :lid)',
				array('lid' => $locationId));
			$data['lectures'] = $lectures['cnt'];
			$data['haveDozmod'] = true;
		}
		// Get clients matching this location's subnet(s)
		$count = $online = $used = 0;
		if (Module::get('statistics') !== false) {
			$mres = Database::simpleQuery("SELECT lastseen, logintime FROM machine"
				. " INNER JOIN subnet ON (INET_ATON(machine.clientip) BETWEEN startaddr AND endaddr)"
				. " WHERE subnet.locationid = :lid OR machine.locationid = :lid", array('lid' => $locationId));
			$DL = time() - 605;
			while ($row = $mres->fetch(PDO::FETCH_ASSOC)) {
				$count++;
				if ($row['lastseen'] > $DL) {
					$online++;
					if ($row['logintime'] != 0) {
						$used++;
					}
				}
			}
			$data['haveStatistics'] = true;
		}
		$data['machines'] = $count;
		$data['machines_online'] = $online;
		$data['machines_used'] = $used;
		$data['used_percent'] = $online === 0 ? 0 : round(100 * $used / $online);
		echo Render::parse('location-subnets', $data);
	}
	
	/*
	 * Helpers
	 */
	
	private function rangeToLong($start, $end)
	{
		$startLong = ip2long($start);
		$endLong = ip2long($end);
		if ($startLong !== false) {
			$startLong = sprintf("%u", $startLong);
		}
		if ($endLong !== false) {
			$endLong = sprintf("%u", $endLong);
		}
		return array($startLong, $endLong);
	}

	private function rangeToLongVerbose($start, $end)
	{
		$result = $this->rangeToLong($start, $end);
		list($startLong, $endLong) = $result;
		if ($startLong === false) {
			Message::addWarning('main.value-invalid', 'start addr', $start);
		}
		if ($endLong === false) {
			Message::addWarning('main.value-invalid', 'end addr', $start);
		}
		if ($startLong === false || $endLong === false)
			return false;
		if ($startLong > $endLong) {
			Message::addWarning('main.value-invalid', 'range', $start . ' - ' . $end);
			return false;
		}
		return $result;
	}

}
