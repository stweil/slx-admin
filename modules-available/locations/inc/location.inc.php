<?php

class Location
{

	private static $flatLocationCache = false;
	private static $assocLocationCache = false;
	private static $treeCache = false;

	private static function getTree()
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
				'parentlocationid' => (int)$node['parentlocationid'],
				'parents' => $parents,
				'locationname' => $node['locationname'],
				'depth' => $depth
			);
			if (!empty($node['children'])) {
				$output += self::flattenTreeAssoc($node['children'], array_merge($parents, array((int)$node['locationid'])), $depth + 1);
			}
		}
		return $output;
	}

	public static function getLocations($default = 0, $excludeId = 0, $addNoParent = false)
	{
		if (self::$flatLocationCache === false) {
			$rows = self::getTree();
			$rows = self::flattenTree($rows);
			self::$flatLocationCache = $rows;
		} else {
			$rows = self::$flatLocationCache;
		}
		$del = false;
		unset($row);
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
			if ($row['locationid'] == $default) {
				$row['selected'] = true;
			}
		}
		if ($addNoParent) {
			array_unshift($rows, array(
				'locationid' => 0,
				'locationname' => '-----',
				'selected' => $default == 0
			));
		}
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
			$output[] = array(
				'locationid' => $node['locationid'],
				'locationname' => $node['locationname'],
				'locationpad' => str_repeat('--', $depth),
				'locationspan1' => $depth + 1,
				'locationspan2' => 10 - $depth,
				'depth' => $depth
			);
			if (!empty($node['children'])) {
				$output = array_merge($output, self::flattenTree($node['children'], $depth + 1));
			}
		}
		return $output;
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
	 * Get closest location by matching subnets. Deepest match in tree wins.
	 *
	 * @param string $ip IP address of client
	 * @return bool|int locationid, or false if no match
	 */
	public static function getFromIp($ip)
	{
		$locationId = false;
		$long = sprintf('%u', ip2long($ip));
		$net = Database::simpleQuery('SELECT locationid FROM subnet'
			. ' WHERE :ip BETWEEN startaddr AND endaddr', array('ip' => $long));
		while ($row = $net->fetch(PDO::FETCH_ASSOC)) {
			$locations = self::getLocationsAssoc();
			$id = (int)$row['locationid'];
			if (!isset($locations[$id]))
				continue;
			if ($locationId !== false && $locations[$id]['depth'] <= $locations[$locationId]['depth'])
				continue;
			$locationId = $id;
		}
		return $locationId;
	}

	/**
	 * Get all location IDs from the given location up to the root.
	 *
	 * @param int $locationId
	 * @return int[] location ids, including $locationId
	 */
	public function getLocationRootChain($locationId)
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
	public static function getSubnetsByLocation(&$overlapSelf, &$overlapOther)
	{
		$locs = self::getLocationsAssoc();
		$subnets = self::getSubnets();
		// Find locations having nets overlapping with themselves if array was passed
		if ($overlapSelf === true || $overlapOther === true) {
			self::findOverlap($locs, $subnets, $overlapSelf, $overlapOther);
		}
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
				$lid = $locs[$lid]['parentlocationid'];
			}
		}
		return $locs;
	}

	private static function findOverlap($locs, $subnets, &$overlapSelf, &$overlapOther)
	{
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

	private static function overlap($net1, $net2)
	{
		return ($net1['startaddr'] >= $net2['startaddr'] && $net1['startaddr'] <= $net2['endaddr'])
		|| ($net1['endaddr'] >= $net2['startaddr'] && $net1['endaddr'] <= $net2['endaddr']);
	}

}
