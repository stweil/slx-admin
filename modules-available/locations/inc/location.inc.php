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

	/**
	 * Return row from location table for $locationId.
	 * @param $locationId
	 * @return array|bool row from DB, false if not found
	 */
	public static function get($locationId)
	{
		return Database::queryFirst("SELECT * FROM location WHERE locationid = :locationId", compact('locationId'));
	}

	/**
	 * Get name of location
	 * @param int $locationId id of location to get name for
	 * @return string|false Name of location, false if locationId doesn't exist
	 */
	public static function getName($locationId)
	{
		$locationId = (int)$locationId;
		if (self::$assocLocationCache === false) {
			self::getLocationsAssoc();
		}
		if (!isset(self::$assocLocationCache[$locationId]))
			return false;
		return self::$assocLocationCache[$locationId]['locationname'];
	}

	/**
	 * Get all the names of the given location and its parents, up
	 * to the root element. Array keys will be locationids, value the names.
	 * @param int $locationId
	 * @return array|false locations, from furthest to nearest or false if locationId doesn't exist
	 */
	public static function getNameChain($locationId)
	{
		if (self::$assocLocationCache === false) {
			self::getLocationsAssoc();
		}
		$locationId = (int)$locationId;
		if (!isset(self::$assocLocationCache[$locationId]))
			return false;
		$ret = array();
		while (isset(self::$assocLocationCache[$locationId])) {
			$ret[$locationId] = self::$assocLocationCache[$locationId]['locationname'];
			$locationId = self::$assocLocationCache[$locationId]['parentlocationid'];
		}
		return array_reverse($ret, true);
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
			$cc = empty($node['children']) ? array() : array_map(function ($item) { return (int)$item['locationid']; }, $node['children']);
			$output[(int)$node['locationid']] = array(
				'locationid' => (int)$node['locationid'],
				'parentlocationid' => (int)$node['parentlocationid'],
				'parents' => $parents,
				'children' => $cc,
				'directchildren' => $cc,
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
		$selected = (int)$selected;
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

	/**
	 * Get nested array of all the locations and children of given locationid(s).
	 *
	 * @param int[]|int $idList List of location ids
	 * @param bool $locationTree used in recursive calls, don't pass
	 * @return array list of passed locations plus their children
	 */
	public static function getRecursive($idList, $locationTree = false)
	{
		if (!is_array($idList)) {
			$idList = array($idList);
		}
		if ($locationTree === false) {
			$locationTree = self::getTree();
		}
		$ret = array();
		foreach ($locationTree as $location) {
			if (in_array($location['locationid'], $idList)) {
				$ret[] = $location;
			} elseif (!empty($location['children'])) {
				$ret = array_merge($ret, self::getRecursive($idList, $location['children']));
			}
		}
		return $ret;
	}

	/**
	 * Get flat array of all the locations and children of given locationid(s).
	 *
	 * @param int[]|int $idList List of location ids
	 * @return array list of passed locations plus their children
	 */
	public static function getRecursiveFlat($idList)
	{
		$ret = self::getRecursive($idList);
		if (!empty($ret)) {
			$ret = self::flattenTree($ret);
		}
		return $ret;
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
		$result = (bool)$result;
		return $result;
	}

	public static function extractIds($tree)
	{
		$ids = array();
		foreach ($tree as $node) {
			$ids[] = (int)$node['locationid'];
			if (!empty($node['children'])) {
				$ids = array_merge($ids, self::extractIds($node['children']));
			}
		}
		return $ids;
	}

	/**
	 * Get location id for given machine (by uuid)
	 * @param string $uuid machine uuid
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
	 * @param bool $honorRoomPlanner consider a fixed location assigned manually by roomplanner
	 * @return bool|int locationid, or false if no match
	 */
	public static function getFromIp($ip, $honorRoomPlanner = false)
	{
		if (Module::get('statistics') !== false) {
			// Shortcut - try to use subnetlocationid in machine table
			if ($honorRoomPlanner) {
				$ret = Database::queryFirst("SELECT locationid AS loc FROM machine
						WHERE clientip = :ip
						ORDER BY lastseen DESC LIMIT 1", compact('ip'));
			} else {
				$ret = Database::queryFirst("SELECT subnetlocationid AS loc FROM machine
						WHERE clientip = :ip
						ORDER BY lastseen DESC LIMIT 1", compact('ip'));
			}
			if ($ret !== false) {
				if ($ret['loc'] > 0) {
					return (int)$ret['loc'];
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
	 * @param string $ip IP address of client
	 * @param string $uuid System-UUID of client
	 * @return int|bool location id, or false if none matches
	 */
	public static function getFromIpAndUuid($ip, $uuid)
	{
		$locationId = false;
		$ipLoc = self::getFromIp($ip);
		if ($ipLoc !== false) {
			// Set locationId to ipLoc for now, it will be overwritten later if another case applies.
			$locationId = $ipLoc;
			if ($uuid !== false) {
				// Machine ip maps to a location, and we have a client supplied uuid (which might not be known if the client boots for the first time)
				$uuidLoc = self::getFromMachineUuid($uuid);
				if (self::isFixedLocationValid($uuidLoc, $ipLoc)) {
					$locationId = $uuidLoc;
				}
			}
		}
		return $locationId;
	}

	public static function isFixedLocationValid($uuidLoc, $ipLoc)
	{
		$uuidLoc = (int)$uuidLoc;
		$ipLoc = (int)$ipLoc;
		if ($uuidLoc === $ipLoc || $uuidLoc === 0)
			return true;
		if ($ipLoc === 0)
			return false; // roomplanner assignment, but no subnet - deny
		// Validate that the location the IP maps to is in the chain we get using the
		// location determined by roomplanner
		$uuidLocations = self::getLocationRootChain($uuidLoc);
		if (!empty(self::$assocLocationCache[$uuidLoc]['children']))
			return false; // roomplanner location isn't actually leaf node, this is not valid
		$idx = array_search($ipLoc, $uuidLocations);
		// If roomplanner loc is max two levels deeper than IP loc, accept
		return $idx !== false && $idx <= 2;
	}

	/**
	 * Get all location IDs from the given location up to the root.
	 *
	 * @param int $locationId
	 * @return int[] location ids, including $locationId
	 */
	public static function getLocationRootChain($locationId)
	{
		$locationId = (int)$locationId;
		if (self::$assocLocationCache === false) {
			self::getLocationsAssoc();
		}
		if (!isset(self::$assocLocationCache[$locationId]))
			return [];
		$chain = self::$assocLocationCache[$locationId]['parents'];
		$chain[] = $locationId;
		return array_reverse($chain);
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
	 * @param string $ip IP to look up
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

}
