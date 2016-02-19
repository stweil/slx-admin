<?php

class Location
{

	private static $flatLocationCache = false;
	private static $assocLocationCache = false;
	
	public static function queryLocations()
	{
		$res = Database::simpleQuery("SELECT locationid, parentlocationid, locationname FROM location");
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$rows[] = $row;
		}
		return $rows;
	}
	
	public static function getLocationsAssoc()
	{
		if (self::$assocLocationCache === false) {
			$rows = self::queryLocations();
			$rows = self::buildTree($rows);
			self::$assocLocationCache = self::flattenTreeAssoc($rows);
		}
		return self::$assocLocationCache;
	}
	
	private static function flattenTreeAssoc($tree, $depth = 0)
	{
		$output = array();
		foreach ($tree as $node) {
			$output[(int)$node['locationid']] = array(
				'parentlocationid' => (int)$node['parentlocationid'],
				'locationname' => $node['locationname'],
				'depth' => $depth
			);
			if (!empty($node['children'])) {
				$output += self::flattenTreeAssoc($node['children'], $depth + 1);
			}
		}
		return $output;
	}

	public static function getLocations($default = 0, $excludeId = 0, $addNoParent = false)
	{
		if (self::$flatLocationCache === false) {
			$rows = self::queryLocations();
			$rows = self::buildTree($rows);
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
	
	public static function getFromIp($ip)
	{
		$locationId = false;
		$long = sprintf('%u', ip2long($ip));
		$net = Database::simpleQuery('SELECT locationid FROM subnet'
			. ' WHERE :ip BETWEEN startaddr AND endaddr', array('ip' => $long));
		while ($row = $net->fetch(PDO::FETCH_ASSOC)) {
			$locations = self::getLocationsAssoc();
			$id = (int)$row['locationid'];
			if (!isset($locations[$id])) continue;
			if ($locationId !== false && $locations[$id]['depth'] <= $locations[$locationId]['depth']) continue;
			$locationId = $id;
		}
		return $locationId;
	}

}
