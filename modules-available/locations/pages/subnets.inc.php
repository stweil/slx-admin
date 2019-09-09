<?php

class SubPage
{

	public static function doPreprocess($action)
	{
		if ($action === 'updatesubnets') {
			self::updateSubnets();
			return true;
		}
		return false;
	}

	private static function updateSubnets()
	{
		User::assertPermission('subnets.edit', NULL, '?do=locations');
		$editCount = 0;
		$deleteCount = 0;
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
			$start = trim($start);
			$end = trim($ends[$subnetid]);
			if (empty($start) && empty($end)) {
				$ret = Database::exec('DELETE FROM subnet WHERE subnetid = :subnetid', compact('subnetid'));
				$deleteCount += $ret;
				continue;
			}
			if (!isset($existingLocs[$loc])) {
				Message::addError('main.value-invalid', 'locationid', $loc);
				continue;
			}
			$range = LocationUtil::rangeToLongVerbose($start, $end);
			if ($range === false)
				continue;
			list($startLong, $endLong) = $range;
			if ($stmt->execute(compact('startLong', 'endLong', 'loc', 'subnetid'))) {
				$editCount += $stmt->rowCount();
			}
		}
		AutoLocation::rebuildAll();
		if ($editCount > 0) {
			Message::addSuccess('subnets-updated', $editCount);
		}
		if ($deleteCount > 0) {
			Message::addSuccess('subnets-deleted', $deleteCount);
		}
		Util::redirect('?do=Locations');
	}

	public static function doRender($getAction)
	{
		if ($getAction === false) {
			User::assertPermission('subnets.edit', NULL, '?do=locations');
			$res = Database::simpleQuery("SELECT subnetid, startaddr, endaddr, locationid
					FROM subnet
					ORDER BY startaddr ASC, endaddr DESC");
			$rows = array();
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$row['startaddr'] = long2ip($row['startaddr']);
				$row['endaddr'] = long2ip($row['endaddr']);
				$row['locations'] = Location::getLocations($row['locationid']);
				$rows[] = $row;
			}
			$data = array('list' => $rows);
			Permission::addGlobalTags($data['perms'], NULL, ['location.view']);
			Render::addTemplate('subnets', $data);
			return true;
		}
		return false;
	}

	public static function doAjax($action)
	{
		return false;
	}

	/*
	 * Helpers
	 */

}