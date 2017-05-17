<?php

class Location
{

	private static $flatLocationCache = false;
	private static $assocLocationCache = false;
	private static $treeCache = false;
	private static $subnetMapCache = false;

	public static function getTree()
	{
		if (self::$treeCache === false) {
			self::$treeCache = self::queryLocations();
			self::$treeCache = self::buildTree(self::$treeCache);
		}
		return self::$treeCache;
	}

	public static function queryLocations()
	{
		$res = Database::simpleQuery("SELECT locationid, parentlocationid, locationname FROM location");
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$rows[] = $row;
		}
		return $rows;
	}

	public static function get($locationId)
	{
		return Database::queryFirst("SELECT * FROM location WHERE locationid = :locationId", compact('locationId'));
	}

	public static function getName($locationId)
	{
		self::getLocationsAssoc();
		$locationId = (int)$locationId;
		if (!isset(self::$assocLocationCache[$locationId]))
			return false;
		return self::$assocLocationCache[$locationId]['locationname'];
	}

	public static function getLocationsAssoc()
	{
		if (self::$assocLocationCache === false) {
			$rows = self::getTree();
			self::$assocLocationCache = self::flattenTreeAssoc($rows);
		}
		return self::$assocLocationCache;
	}

	private static function flattenTreeAssoc($tree, $parents = array(), $depth = 0)
	{
		if ($depth > 20) {
			Util::traceError('Recursive location definition detected at ' . print_r($tree, true));
		}
		$output = array();
		foreach ($tree as $node) {
			$output[(int)$node['locationid']] = array(
				'locationid' => (int)$node['locationid'],
				'parentlocationid' => (int)$node['parentlocationid'],
				'parents' => $parents,
				'children' => empty($node['children']) ? array() : array_map(function ($item) { return $item['locationid']; }, $node['children']),
				'locationname' => $node['locationname'],
				'depth' => $depth,
				'isleaf' => true,
			);
			if (!empty($node['children'])) {
				$childNodes = self::flattenTreeAssoc($node['children'], array_merge($parents, array((int)$node['locationid'])), $depth + 1);
				$output[(int)$node['locationid']]['children'] = array_merge($output[(int)$node['locationid']]['children'],
					array_reduce($childNodes, function ($carry, $item) {
						return array_merge($carry, $item['children']);
					}, array()));
				$output += $childNodes;
			}
		}
		foreach ($output as &$entry) {
			if (!isset($output[$entry['parentlocationid']]))
				continue;
			$output[$entry['parentlocationid']]['isleaf'] = false;
		}
		return $output;
	}

	public static function getLocations($selected = 0, $excludeId = 0, $addNoParent = false, $keepArrayKeys = false)
	{
		if (is_string($selected)) {
			settype($selected, 'int');
		}
		if (self::$flatLocationCache === false) {
			$rows = self::getTree();
			$rows = self::flattenTree($rows);
			self::$flatLocationCache = $rows;
		} else {
			$rows = self::$flatLocationCache;
		}
		$del = false;
		unset($row);
		$index = 0;
		foreach ($rows as $key => &$row) {
			if ($del === false && $row['locationid'] == $excludeId) {
				$del = $row['depth'];
			} elseif ($del !== false && $row['depth'] <= $del) {
				$del = false;
			}
			if ($del !== false) {
				unset($rows[$key]);
				continue;
			}
			if ((is_array($selected) && in_array($row['locationid'], $selected)) || (int)$row['locationid'] === $selected) {
				$row['selected'] = true;
			}
			$row['sortIndex'] = $index++;
		}
		if ($addNoParent) {
			array_unshift($rows, array(
				'locationid' => 0,
				'locationname' => '-----',
				'selected' => $selected === 0
			));
		}
		if ($keepArrayKeys)
			return $rows;
		return array_values($rows);
	}

	public static function buildTree($elements, $parentId = 0)
	{
		$branch = array();
		$sort = array();
		foreach ($elements as $element) {
			if ($element['locationid'] == 0 || $element['locationid'] == $parentId)
				continue;
			if ($element['parentlocationid'] == $parentId) {
				$children = self::buildTree($elements, $element['locationid']);
				if (!empty($children)) {
					$element['children'] = $children;
				}
				$branch[] = $element;
				$sort[] = $element['locationname'];
			}
		}
		array_multisort($sort, SORT_ASC, $branch);
		return $branch;
	}

	private static function flattenTree($tree, $depth = 0)
	{
		if ($depth > 20) {
			Util::traceError('Recursive location definition detected at ' . print_r($tree, true));
		}
		$output = array();
		foreach ($tree as $node) {
			$output[(int)$node['locationid']] = array(
				'locationid' => $node['locationid'],
				'parentlocationid' => $node['parentlocationid'],
				'locationname' => $node['locationname'],
				'locationpad' => str_repeat('--', $depth),
				'isleaf'	=> empty($node['children']),
				'depth' => $depth
			);
			if (!empty($node['children'])) {
				$output += self::flattenTree($node['children'], $depth + 1);
			}
		}
		return $output;
	}

	public static function isLeaf($locationid) {
		$result = Database::queryFirst('SELECT COUNT(locationid) = 0 AS isleaf '
			. 'FROM location '
			. 'WHERE parentlocationid = :locationid', ['locationid' => $locationid]);
		$result = $result['isleaf'];
		settype($result, 'bool');
		return $result;
	}

	public static function extractIds($tree)
	{
		$ids = array();
		foreach ($tree as $node) {
			$ids[] = $node['locationid'];
			if (!empty($node['children'])) {
				$ids = array_merge($ids, self::extractIds($node['children']));
			}
		}
		return $ids;
	}

	/**
	 * Get location id for given machine (by uuid)
	 * @param $uuid machine uuid
	 * @return bool|int locationid, false if no match
	 */
	public static function getFromMachineUuid($uuid)
	{
		// Only if we have the statistics module which supplies the machine table
		if (Module::get('statistics') === false)
			return false;
		$ret = Database::queryFirst("SELECT locationid FROM machine WHERE machineuuid = :uuid", compact('uuid'));
		if ($ret === false || !$ret['locationid'])
			return false;
		return (int)$ret['locationid'];
	}

	/**
	 * Get closest location by matching subnets. Deepest match in tree wins.
	 * Ignores any manually assigned locationid (fixedlocationid).
	 *
	 * @param string $ip IP address of client
	 * @return bool|int locationid, or false if no match
	 */
	public static function getFromIp($ip)
	{
		if (Module::get('statistics') !== false) {
			// Shortcut - try to use subnetlocationid in machine table
			$ret = Database::queryFirst("SELECT subnetlocationid FROM machine WHERE clientip = :ip", compact('ip'));
			if ($ret !== false) {
				if ($ret['subnetlocationid'] > 0) {
					return (int)$ret['subnetlocationid'];
				}
				return false;
			}
		}
		return self::mapIpToLocation($ip);
	}

	/**
	 * Combined "intelligent" fetching of locationId by IP and UUID of
	 * client. We can't trust the UUID too much as it is provided by the
	 * client, so if it seems too fishy, the UUID will be ignored.
	 *
	 * @param $ip IP address of client
	 * @param $uuid System-UUID of client
	 * @return int|bool location id, or false if none matches
	 */
	public static function getFromIpAndUuid($ip, $uuid)
	{
		$locationId = false;
		$ipLoc = self::getFromIp($ip);
		if ($ipLoc !== false && $uuid !== false) {
			// Machine ip maps to a location, and we have a client supplied uuid
			$uuidLoc = self::getFromMachineUuid($uuid);
			if ($uuidLoc === $ipLoc) {
				$locationId = $uuidLoc;
			} else if ($uuidLoc !== false) {
				// Validate that the location the IP maps to is in the chain we get using the
				// location determined by the uuid
				$uuidLocations = self::getLocationRootChain($uuidLoc);
				$ipLocations = self::getLocationRootChain($ipLoc);
				if (in_array($uuidLoc, $ipLocations)
					|| (in_array($ipLoc, $uuidLocations) && count($ipLocations) + 1 >= count($uuidLocations))
				) {
					// Close enough, allow
					$locationId = $uuidLoc;
				} else {
					// UUID and IP disagree too much, play safe and ignore the UUID
					$locationId = $ipLoc;
				}
			}
		} else if ($ipLoc !== false) {
			// No uuid, but ip maps to location; use that
			$locationId = $ipLoc;
		}
		return $locationId;
	}

	/**
	 * Get all location IDs from the given location up to the root.
	 *
	 * @param int $locationId
	 * @return int[] location ids, including $locationId
	 */
	public static function getLocationRootChain($locationId)
	{
		settype($locationId, 'integer');
		$locations = Location::getLocationsAssoc();
		$find = $locationId;
		$matchingLocations = array();
		while (isset($locations[$find]) && !in_array($find, $matchingLocations)) {
			$matchingLocations[] = $find;
			$find = (int)$locations[$find]['parentlocationid'];
		}
		return $matchingLocations;
	}

	/**
	 * @param $locationId
	 * @return bool|array ('value' => x, 'display' => y), false if no parent or unknown id
	 */
	public static function getBaseconfigParent($locationId)
	{
		settype($locationId, 'integer');
		$locations = Location::getLocationsAssoc();
		if (!isset($locations[$locationId]))
			return false;
		$locationId = (int)$locations[$locationId]['parentlocationid'];
		if (!isset($locations[$locationId]))
			return false;
		return array('value' => $locationId, 'display' => $locations[$locationId]['locationname']);
	}

	/**
	 * @return array list of subnets as numeric array
	 */
	public static function getSubnets()
	{
		$res = Database::simpleQuery("SELECT startaddr, endaddr, locationid FROM subnet");
		$subnets = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			settype($row['locationid'], 'int');
			$subnets[] = $row;
		}
		return $subnets;
	}

	public static function getOverlappingSubnets(&$overlapSelf = false, &$overlapOther = false)
	{
		if ($overlapSelf === false && $overlapOther === false) {
			return;
		}
		$locs = self::getLocationsAssoc();
		$subnets = self::getSubnets();
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
				$overlapSelf[]['locationname'] = $locs[$entry['locationid']]['locationname'];
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
	 * @return array|bool assoc array mapping from locationid to subnets
	 */
	public static function getSubnetsByLocation($recursive = false)
	{
		$locs = self::getLocationsAssoc();
		$subnets = self::getSubnets();
		// Accumulate - copy up subnet definitions
		foreach ($locs as &$loc) {
			$loc['subnets'] = array();
		}
		unset($loc);
		foreach ($subnets as $subnet) {
			$lid = $subnet['locationid'];
			while (isset($locs[$lid])) {
				$locs[$lid]['subnets'][] = array(
					'startaddr' => $subnet['startaddr'],
					'endaddr' => $subnet['endaddr']
				);
				if (!$recursive)
					break;
				$lid = $locs[$lid]['parentlocationid'];
			}
		}
		return $locs;
	}

	/**
	 * Lookup $ip in subnets, try to find one that matches
	 * and return its locationid.
	 * If two+ subnets match, the one which is nested deeper wins.
	 * If two+ subnets match and have the same depth, the one which
	 * is smaller wins.
	 * If two+ subnets match and have the same depth and size, a
	 * random one will be returned.
	 *
	 * @param $ip IP to look up
	 * @return bool|int locationid ip matches, false = no match
	 */
	public static function mapIpToLocation($ip)
	{
		if (self::$subnetMapCache === false) {
			self::$subnetMapCache = self::getSubnetsByLocation();
		}
		$long = sprintf('%u', ip2long($ip));
		$best = false;
		$bestSize = 0;
		foreach (self::$subnetMapCache as $lid => $data) {
			if ($best !== false && self::$subnetMapCache[$lid]['depth'] < self::$subnetMapCache[$best]['depth'])
				continue; // Don't even need to take a look
			foreach ($data['subnets'] as $subnet) {
				if ($long < $subnet['startaddr'] || $long > $subnet['endaddr'])
					continue; // Nope
				if ($best !== false // Already have a best candidate
						&& self::$subnetMapCache[$lid]['depth'] === self::$subnetMapCache[$best]['depth'] // Same depth
						&& $bestSize < $subnet['endaddr'] - $subnet['startaddr']) { // Old candidate has smaller subnet
					// So we ignore this one as the old one is more specific
					continue;
				}
				$bestSize = $subnet['endaddr'] - $subnet['startaddr'];
				$best = $lid;
			}
		}
		if ($best === false)
			return false;
		return (int)$best;
	}

	public static function updateMapIpToLocation($uuid, $ip)
	{
		$loc = self::mapIpToLocation($ip);
		if ($loc === false) {
			Database::exec("UPDATE machine SET subnetlocationid = NULL WHERE machineuuid = :uuid", compact('uuid'));
		} else {
			Database::exec("UPDATE machine SET subnetlocationid = :loc WHERE machineuuid = :uuid", compact('loc', 'uuid'));
		}
	}

	private static function overlap($net1, $net2)
	{
		return ($net1['startaddr'] <= $net2['endaddr'] && $net1['endaddr'] >= $net2['startaddr']);
	}

}
