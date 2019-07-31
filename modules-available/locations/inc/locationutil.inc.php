<?php

class LocationUtil
{

	/**
	 * @param array $overlapSelf List of locations which have subnet definitions that overlap with itself
	 * @param array $overlapOther List of location pairs which have overlapping subnets
	 */
	public static function getOverlappingSubnets(&$overlapSelf = false, &$overlapOther = false)
	{
		if ($overlapSelf === false && $overlapOther === false) {
			return;
		}
		$locs = Location::getLocationsAssoc();
		$subnets = Location::getSubnets();
		if ($overlapSelf) {
			$self = array();
		}
		if ($overlapOther) {
			$other = array();
		}
		$cnt = count($subnets);
		for ($i = 0; $i < $cnt; ++$i) {
			for ($j = $i + 1; $j < $cnt; ++$j) {
				if ($overlapSelf && $subnets[$i]['locationid'] === $subnets[$j]['locationid']
					&& self::overlap($subnets[$i], $subnets[$j])
				) {
					$self[$subnets[$i]['locationid']] = $subnets[$i]['locationid'];
				}
				if ($overlapOther && $subnets[$i]['locationid'] !== $subnets[$j]['locationid']
					&& self::overlap($subnets[$i], $subnets[$j])
				) {
					$a = min($subnets[$i]['locationid'], $subnets[$j]['locationid']);
					$b = max($subnets[$i]['locationid'], $subnets[$j]['locationid']);
					$other["$a|$b"] = array('lid1' => $subnets[$i]['locationid'], 'lid2' => $subnets[$j]['locationid']);
				}
			}
		}
		if ($overlapSelf) {
			$overlapSelf = array();
			foreach ($self as $entry) {
				if (!isset($locs[$entry]))
					continue;
				$overlapSelf[]['locationname'] = $locs[$entry]['locationname'];
			}
		}
		if ($overlapOther) {
			$overlapOther = array();
			foreach ($other as $entry) {
				if (!isset($locs[$entry['lid1']]) || !isset($locs[$entry['lid2']]))
					continue;
				if (in_array($entry['lid1'], $locs[$entry['lid2']]['parents']) || in_array($entry['lid2'], $locs[$entry['lid1']]['parents']))
					continue;
				if (isset($locs[$entry['lid1']])) {
					$entry['name1'] = $locs[$entry['lid1']]['locationname'];
				}
				if (isset($locs[$entry['lid2']])) {
					$entry['name2'] = $locs[$entry['lid2']]['locationname'];
				}
				$overlapOther[] = $entry;
			}
		}
	}

	/**
	 * Get information about machines where the location assigned by roomplanner
	 * mismatches what the subnet configuration says.
	 * If $locationId is 0, return list of all locations where a mismatch occurs,
	 * grouped by the location the client was assigned to via roomplanner.
	 * Otherwise, just return an assoc array with the requested locationid, name
	 * and a list of all clients that are wrongfully assigned to that room.
	 * @param int $locationId
	 * @return array
	 */
	public static function getMachinesWithLocationMismatch($locationId = 0, $checkPerms = false)
	{
		$locationId = (int)$locationId;
		if ($checkPerms) {
			if ($locationId !== 0) {
				// Query details for specific location -- use assert and fake array
				User::assertPermission('.roomplanner.edit', $locationId);
				$roomplannerLocs = [$locationId];
			} else {
				// Query summary for all locations -- get actual list
				$roomplannerLocs = User::getAllowedLocations('.roomplanner.edit');
			}
			if (User::hasPermission('subnets.edit')) {
				$ipLocs = [0];
			} else {
				$ipLocs = User::getAllowedLocations('location.edit.subnets');
			}
			if (in_array(0, $ipLocs)) {
				$ipLocs = true;
			}
			if (in_array(0, $roomplannerLocs)) {
				$roomplannerLocs = true;
			}
			if ($ipLocs === true && $roomplannerLocs === true) {
				$checkPerms = false; // User can do everything
			} elseif ($ipLocs === true || $roomplannerLocs === true) {
				$combinedLocs = true;
			} else {
				$combinedLocs = array_unique(array_merge($ipLocs, $roomplannerLocs));
			}
		}
		if ($checkPerms && empty($combinedLocs))
			return [];
		if ($locationId === 0) {
			if (!$checkPerms || $combinedLocs === true) {
				$extra = 'IS NOT NULL';
				$params = [];
			} else {
				$extra = 'IN (:locs)';
				$params = ['locs' => $combinedLocs];
			}
			$query = "SELECT subnetlocationid, fixedlocationid
				FROM machine WHERE fixedlocationid $extra";
		} else {
			$query = "SELECT machineuuid, hostname, clientip, subnetlocationid, fixedlocationid
				FROM machine WHERE fixedlocationid = :locationid";
			$params = ['locationid' => $locationId];
		}
		$res = Database::simpleQuery($query, $params);
		$return = [];
		$locs = false;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['subnetlocationid'] === $row['fixedlocationid'])
				continue;
			if (Location::isUuidLocationValid((int)$row['fixedlocationid'], (int)$row['subnetlocationid']))
				continue;
			$lid = (int)$row['fixedlocationid'];
			if (!isset($return[$lid])) {
				if ($locs === false) {
					$locs = Location::getLocationsAssoc();
				}
				$return[$lid] = [
					'locationid' => $lid,
					'locationname' => $locs[$lid]['locationname'],
					'clients' => [],
					'count' => 0,
				];
			}
			if ($locationId === 0) {
				$return[$lid]['count']++;
			} else {
				$slid = (int)$row['subnetlocationid'];
				$return[$lid]['clients'][] = [
					'machineuuid' => $row['machineuuid'],
					'hostname' => $row['hostname'],
					'clientip' => $row['clientip'],
					'iplocationid' => $slid,
					'iplocationname' => $locs[$slid]['locationname'],
					'canmove' => !$checkPerms || $ipLocs === true || in_array($slid, $ipLocs), // Can machine be moved to subnet's locationid?
				];
			}
		}
		if (empty($return))
			return $return;
		if ($locationId === 0) {
			return array_values($return);
		} else {
			return $return[$locationId];
		}
	}

	private static function overlap($net1, $net2)
	{
		return ($net1['startaddr'] <= $net2['endaddr'] && $net1['endaddr'] >= $net2['startaddr']);
	}

	public static function rangeToLongVerbose($start, $end)
	{
		$result = self::rangeToLong($start, $end);
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

	public static function rangeToLong($start, $end)
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

}
