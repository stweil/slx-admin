<?php

class SubPage
{

	public static function doPreprocess($action)
	{
		if ($action === 'updatelocation') {
			self::updateLocation();
			return true;
		}
		return false;
	}

	public static function doRender($action)
	{
		return false;
	}

	public static function doAjax($action)
	{
		if ($action === 'showlocation') {
			self::ajaxShowLocation();
			return true;
		}
		return false;
	}

	private static function updateLocation()
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
			self::deleteLocation($location);
			$change = true;
		}
		// Update subnets
		$change |= self::updateLocationSubnets();
		// Insert subnets
		$change |= self::addNewLocationSubnets($location);
		// Update location!
		$change |= self::updateLocationData($location);

		if ($change) {
			// In case subnets or tree layout changed, recalc this
			AutoLocation::rebuildAll();
		}
		Util::redirect('?do=Locations');
	}

	private static function deleteLocation($location)
	{
		$locationId = (int)$location['locationid'];
		if (Request::post('recursive', false) === 'on') {
			$rows = Location::queryLocations();
			$rows = Location::buildTree($rows, $locationId);
			$ids = Location::extractIds($rows);
		} else {
			$ids = [$locationId];
		}
		$locs = Database::exec("DELETE FROM location WHERE locationid IN (:ids)", ['ids' => $ids]);
		Database::exec('UPDATE location SET parentlocationid = :newparent WHERE parentlocationid = :oldparent', array(
			'newparent' => $location['parentlocationid'],
			'oldparent' => $location['locationid']
		));
		AutoLocation::rebuildAll($ids);
		Message::addSuccess('location-deleted', $locs, implode(', ', $ids));
		Util::redirect('?do=Locations');
	}

	private static function updateLocationData($location)
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

	private static function updateLocationSubnets()
	{
		$locationId = Request::post('locationid', false, 'integer');
		if (!User::hasPermission('location.edit.subnets', $locationId))
			return false;

		$change = false;

		// Deletion first
		$dels = Request::post('deletesubnet', false);
		$deleteCount = 0;
		if (is_array($dels)) {
			$stmt = Database::prepare('DELETE FROM subnet WHERE subnetid = :id');
			foreach ($dels as $subnetid => $value) {
				if (!is_numeric($subnetid) || $value !== 'on')
					continue;
				if ($stmt->execute(array('id' => $subnetid))) {
					$deleteCount += $stmt->rowCount();
				}
			}
		}

		// Now actual updates
		$starts = Request::post('startaddr', false);
		$ends = Request::post('endaddr', false);
		if (!is_array($starts) || !is_array($ends)) {
			return $change;
		}
		$editCount = 0;
		$stmt = Database::prepare('UPDATE subnet SET startaddr = :start, endaddr = :end'
			. ' WHERE subnetid = :id');
		foreach ($starts as $subnetid => $start) {
			if (!isset($ends[$subnetid]) || !is_numeric($subnetid))
				continue;
			$start = trim($start);
			$end = trim($ends[$subnetid]);
			if (empty($start) && empty($end)) {
				$ret = Database::exec('DELETE FROM subnet WHERE subnetid = :id', ['id' => $subnetid]);
				$deleteCount += $ret;
				continue;
			}
			$range = LocationUtil::rangeToLongVerbose($start, $end);
			if ($range === false)
				continue;
			list($startLong, $endLong) = $range;
			if ($stmt->execute(array('id' => $subnetid, 'start' => $startLong, 'end' => $endLong))) {
				$editCount += $stmt->rowCount();
			}
		}
		if ($editCount > 0) {
			Message::addSuccess('subnets-updated', $editCount);
			$change = true;
		}
		if ($deleteCount > 0) {
			Message::addSuccess('subnets-deleted', $deleteCount);
			$change = true;
		}
		return $change;
	}

	private static function addNewLocationSubnets($location)
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
			list($startLong, $endLong) = LocationUtil::rangeToLong($start, $end);
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

	private static function ajaxShowLocation()
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
			'roomplanner' => Module::get('roomplanner') !== false,
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

}