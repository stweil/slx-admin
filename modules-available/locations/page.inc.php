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
		if (!User::isLoggedIn()) {
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
		if (Request::isPost()) {
			Util::redirect('?do=locations');
		}
	}

	private function updateSubnets()
	{
		User::assertPermission('subnets.edit', NULL, '?do=locations');
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
		AutoLocation::rebuildAll();
		Message::addSuccess('subnets-updated', $count);
		Util::redirect('?do=Locations');
	}

	private function addLocations()
	{
		$names = Request::post('newlocation', false);
		$parents = Request::post('newparent', []);
		if (!is_array($names) || !is_array($parents)) {
			Message::addError('main.empty-field');
			Util::redirect('?do=Locations');
		}
		$locs = Location::getLocations();
		$count = 0;
		foreach ($names as $idx => $name) {
			$name = trim($name);
			if (empty($name))
				continue;
			$parent = isset($parents[$idx]) ? (int)$parents[$idx] : 0;
			if (!User::hasPermission("location.add", $parent)) {
				Message::addError('no-permission-location', isset($locs[$parent]) ? $locs[$parent]['locationname'] : $parent);
				continue;
			}
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
		$change = false;
		// Delete location?
		if ($locationId === $del) {
			User::assertPermission("location.delete", $locationId, '?do=locations');
			$this->deleteLocation($location);
			$change = true;
		}
		// Update subnets
		$change |= $this->updateLocationSubnets();
		// Insert subnets
		$change |= $this->addNewLocationSubnets($location);
		// Update location!
		$change |= $this->updateLocationData($location);

		if ($change) {
			// In case subnets or tree layout changed, recalc this
			AutoLocation::rebuildAll();
		}
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
		if (!User::hasPermission('location.edit.name', $locationId)) {
			$newName = $location['locationname'];
		} elseif ($newName === false || preg_match('/^\s*$/', $newName)) {
			if ($newName !== false) {
				Message::addWarning('main.value-invalid', 'location name', $newName);
			}
			$newName = $location['locationname'];
		}
		if ($newParent === false || !User::hasPermission('location.edit.parent', $locationId)
				|| !User::hasPermission('location.edit.parent', $newParent)
				|| !User::hasPermission('location.edit.*', $location['parentlocationid'])) {
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
		// TODO: Check permissions for new parent (only if changed)
		$ret = Database::exec('UPDATE location SET parentlocationid = :parent, locationname = :name'
			. ' WHERE locationid = :lid', array(
			'lid' => $locationId,
			'parent' => $newParent,
			'name' => $newName
		));
		if ($ret > 0) {
			Message::addSuccess('location-updated', $newName);
		}
		return $newParent != $location['parentlocationid'];
	}

	private function updateLocationSubnets()
	{
		$locationId = Request::post('locationid', false, 'integer');
		if (!User::hasPermission('location.edit.subnets', $locationId))
			return false;

		$change = false;

		// Deletion first
		$dels = Request::post('deletesubnet', false);
		if (is_array($dels)) {
			$count = 0;
			$stmt = Database::prepare('DELETE FROM subnet WHERE subnetid = :id');
			foreach ($dels as $key => $value) {
				if (!is_numeric($key) || $value !== 'on')
					continue;
				if ($stmt->execute(array('id' => $key))) {
					$count += $stmt->rowCount();
				}
			}
			if ($count > 0) {
				Message::addInfo('subnets-deleted', $count);
				$change = true;
			}
		}

		// Now actual updates
		$starts = Request::post('startaddr', false);
		$ends = Request::post('endaddr', false);
		if (!is_array($starts) || !is_array($ends)) {
			return $change;
		}
		$count = 0;
		$stmt = Database::prepare('UPDATE subnet SET startaddr = :start, endaddr = :end'
			. ' WHERE subnetid = :id');
		foreach ($starts as $key => $start) {
			if (!isset($ends[$key]) || !is_numeric($key))
				continue;
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
			$change = true;
		}
		return $change;
	}

	private function addNewLocationSubnets($location)
	{
		$locationId = (int)$location['locationid'];
		if (!User::hasPermission('location.edit.subnets', $locationId))
			return false;

		$change = false;
		$starts = Request::post('newstartaddr', false);
		$ends = Request::post('newendaddr', false);
		if (!is_array($starts) || !is_array($ends)) {
			return $change;
		}
		$count = 0;
		$stmt = Database::prepare('INSERT INTO subnet SET startaddr = :start, endaddr = :end, locationid = :location');
		foreach ($starts as $key => $start) {
			if (!isset($ends[$key]) || !is_numeric($key))
				continue;
			$end = $ends[$key];
			list($startLong, $endLong) = $this->rangeToLong($start, $end);
			if ($startLong === false) {
				Message::addWarning('main.value-invalid', 'new start addr', $start);
			}
			if ($endLong === false) {
				Message::addWarning('main.value-invalid', 'new end addr', $start);
			}
			if ($startLong === false || $endLong === false)
				continue;
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
			$change = true;
		}
		return $change;
	}

	/*
	 * Rendering normal pages
	 */

	protected function doRender()
	{
		$getAction = Request::get('action', false, 'string');
		if ($getAction === false) {
			if (User::hasPermission('location.view')) {
				Util::redirect('?do=locations&action=showlocations');
			} elseif (User::hasPermission('subnets.edit')) {
				Util::redirect('?do=locations&action=showsubnets');
			} else {
				// Trigger permission denied by asserting non-existent permission
				User::assertPermission('location.view');
			}
		}
		if ($getAction === 'showsubnets') {
			User::assertPermission('subnets.edit', NULL, '?do=locations');
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
		} else {
			Util::redirect('?do=locations');
		}
	}

	private function showLocationList()
	{
		// Warn admin about overlapping subnet definitions
		$overlapSelf = $overlapOther = true;
		Location::getOverlappingSubnets($overlapSelf, $overlapOther);
		//$locs = Location::getLocations(0, 0, false, true);
		$locationList = Location::getLocationsAssoc();
		unset($locationList[0]);
		// Statistics: Count machines for each subnet
		$unassigned = false;
		$unassignedLoad = 0;

		// Filter view: Remove locations we can't reach at all, but show parents to locations
		// we have permission to, so the tree doesn't look all weird
		$visibleLocationIds = $allowedLocationIds = User::getAllowedLocations("location.view");
		foreach ($allowedLocationIds as $lid) {
			if (!isset($locationList[$lid]))
				continue;
			$visibleLocationIds = array_merge($visibleLocationIds, $locationList[$lid]['parents']);
		}
		$visibleLocationIds = array_unique($visibleLocationIds);
		foreach (array_keys($locationList) as $lid) {
			if (User::hasPermission('.baseconfig.view', $lid)) {
				$visibleLocationIds[] = $lid;
			} else {
				$locationList[$lid]['havebaseconfig'] = false;
			}
			if (User::hasPermission('.sysconfig.config.view-list', $lid)) {
				$visibleLocationIds[] = $lid;
			} else {
				$locationList[$lid]['havesysconfig'] = false;
			}
			if (User::hasPermission('.statistics.view.list', $lid)) {
				$visibleLocationIds[] = $lid;
			} else {
				$locationList[$lid]['havestatistics'] = false;
			}
			if (User::hasPermission('.serversetup.ipxe.menu.assign', $lid)) {
				$visibleLocationIds[] = $lid;
			} else {
				$locationList[$lid]['haveipxe'] = false;
			}
			if (!in_array($lid, $visibleLocationIds)) {
				unset($locationList[$lid]);
			} elseif (!in_array($lid, $allowedLocationIds)) {
				$locationList[$lid]['show-only'] = true;
			}
		}

		// Client statistics
		if (Module::get('statistics') !== false) {
			$unassigned = 0;
			$extra = '';
			if (in_array(0, $allowedLocationIds)) {
				$extra = ' OR locationid IS NULL';
			}
			$res = Database::simpleQuery("SELECT locationid, Count(*) AS cnt, Sum(If(state = 'OCCUPIED', 1, 0)) AS used
 				 FROM machine WHERE (locationid IN (:allowedLocationIds) $extra) GROUP BY locationid", compact('allowedLocationIds'));
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$locId = (int)$row['locationid'];
				if (isset($locationList[$locId])) {
					$locationList[$locId]['clientCount'] = $row['cnt'];
					$locationList[$locId]['clientLoad'] = round(100 * $row['used'] / $row['cnt']) . ' %';
				} else {
					$unassigned += $row['cnt'];
					$unassignedLoad += $row['used'];
				}
			}
			unset($loc);
			foreach ($locationList as &$loc) {
				if (!in_array($loc['locationid'], $allowedLocationIds))
					continue;
				if (!isset($loc['clientCountSum'])) {
					$loc['clientCountSum'] = 0;
				}
				if (!isset($loc['clientCount'])) {
					$loc['clientCount'] = 0;
					$loc['clientLoad'] = '0%';
				}
				$loc['clientCountSum'] += $loc['clientCount'];
				foreach ($loc['parents'] as $pid) {
					if (!in_array($pid, $allowedLocationIds))
						continue;
					$locationList[(int)$pid]['hasChild'] = true;
					$locationList[(int)$pid]['clientCountSum'] += $loc['clientCount'];
				}
			}
			unset($loc);
		}
		// Show currently active sysconfig for each location
		$defaultConfig = false;
		if (Module::isAvailable('sysconfig')) {
			$confs = SysConfig::getAll();
			foreach ($confs as $conf) {
				if (strlen($conf['locs']) === 0)
					continue;
				$confLocs = explode(',', $conf['locs']);
				foreach ($confLocs as $locId) {
					settype($locId, 'int');
					if ($locId === 0) {
						$defaultConfig = $conf['title'];
					}
					if (!isset($locationList[$locId]))
						continue;
					$locationList[$locId] += array('configName' => $conf['title'], 'configClass' => 'slx-bold');
				}
			}
			$this->propagateFields($locationList, $defaultConfig, 'configName', 'configClass');
		}
		// Count overridden config vars
		if (Module::get('baseconfig') !== false) {
			$res = Database::simpleQuery("SELECT locationid, Count(*) AS cnt FROM `setting_location`
 					WHERE locationid IN (:allowedLocationIds) GROUP BY locationid", compact('allowedLocationIds'));
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$lid = (int)$row['locationid'];
				if (isset($locationList[$lid])) {
					$locationList[$lid]['overriddenVars'] = $row['cnt'];
				}
			}
			// Confusing because the count might be inaccurate within a branch
			//$this->propagateFields($locationList, '', 'overriddenVars', 'overriddenClass');
		}
		// Show ipxe menu
		if (Module::get('serversetup') !== false) {
			$res = Database::simpleQuery("SELECT ml.locationid, m.title, ml.defaultentryid FROM serversetup_menu m
				INNER JOIN serversetup_menu_location ml USING (menuid)
				WHERE locationid IN (:allowedLocationIds) GROUP BY locationid", compact('allowedLocationIds'));
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$lid = (int)$row['locationid'];
				if (isset($locationList[$lid])) {
					if ($row['defaultentryid'] !== null) {
						$row['title'] .= '(*)';
					}
					$locationList[$lid]['customMenu'] = $row['title'];
				}
			}
			$this->propagateFields($locationList, '', 'customMenu', 'customMenuClass');
		}

		$addAllowedLocs = User::getAllowedLocations("location.add");
		$addAllowedList = Location::getLocations(0, 0, true);
		foreach ($addAllowedList as &$loc) {
			if (!in_array($loc["locationid"], $addAllowedLocs)) {
				$loc["disabled"] = "disabled";
			}
		}
		unset($loc);

		// Output
		$data = array(
			'list' => array_values($locationList),
			'havestatistics' => Module::get('statistics') !== false,
			'havebaseconfig' => Module::get('baseconfig') !== false,
			'havesysconfig' => Module::get('sysconfig') !== false,
			'haveipxe' => Module::get('serversetup') !== false,
			'overlapSelf' => $overlapSelf,
			'overlapOther' => $overlapOther,
			'haveOverlapSelf' => !empty($overlapSelf),
			'haveOverlapOther' => !empty($overlapOther),
			'unassignedCount' => $unassigned,
			'unassignedLoad' => round(($unassignedLoad / $unassigned) * 100) . ' %',
			'defaultConfig' => $defaultConfig,
			'addAllowedList' => array_values($addAllowedList),
		);
		// TODO: Buttons for config vars and sysconfig are currently always shown, as their availability
		// depends on permissions in the according modules, not this one
		Permission::addGlobalTags($data['perms'], NULL, ['subnets.edit', 'location.add']);
		Render::addTemplate('locations', $data);
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

		User::assertPermission("location.view", $locationId);

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
			'roomplanner' => Module::get('roomplanner') !== false && Location::isLeaf($locationId),
			'parents' => Location::getLocations($loc['parentlocationid'], $locationId, true)
		);

		// Disable locations in the parent selector where the user cannot change to
		if (!User::hasPermission('location.edit.*', $loc['parentlocationid'])
				|| !User::hasPermission('location.edit.parent', $locationId)) {
			$allowedLocs = [];
		} else {
			$allowedLocs = User::getAllowedLocations("location.edit.*");
			foreach ($data['parents'] as &$parent) {
				if (!(in_array($parent["locationid"], $allowedLocs) || $parent["locationid"] == $loc['parentlocationid'])) {
					$parent["disabled"] = "disabled";
				}
			}
		}

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
			$mres = Database::simpleQuery("SELECT state FROM machine"
				. " WHERE machine.locationid = :lid", array('lid' => $locationId));
			while ($row = $mres->fetch(PDO::FETCH_ASSOC)) {
				$count++;
				if ($row['state'] === 'IDLE') {
					$online++;
				}
				if ($row['state'] === 'OCCUPIED') {
					$online++;
					$used++;
				}
			}
			$data['haveStatistics'] = true;
			// Link
			if (User::hasPermission('.statistics.view.list')) {
				$data['statsLink'] = 'list';
			} elseif (User::hasPermission('.statistics.view.summary')) {
				$data['statsLink'] = 'summary';
			}
		}
		$data['machines'] = $count;
		$data['machines_online'] = $online;
		$data['machines_used'] = $used;
		$data['used_percent'] = $count === 0 ? 0 : round(($used / $count) * 100);


		Permission::addGlobalTags($data['perms'], $locationId, ['location.edit.name', 'location.edit.subnets', 'location.delete', '.roomplanner.edit'], 'save_button');
		if (empty($allowedLocs)) {
			$data['perms']['location']['edit']['parent']['disabled'] = 'disabled';
		} else {
			unset($data['perms']['save_button']);
		}

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

	private function propagateFields(&$locationList, $defaultValue, $name, $class)
	{
		$depth = array();
		foreach ($locationList as &$loc) {
			$d = $loc['depth'];
			if (!isset($loc[$name])) {
				// Has no explicit config assignment
				if ($d === 0) {
					$loc[$name] = $defaultValue;
				} else {
					$loc[$name] = $depth[$d - 1];
				}
				$loc[$class] = 'gray';
			}
			$depth[$d] = $loc[$name];
			unset($depth[$d + 1]);
		}
	}

}
