<?php

class SubPage
{

	public static function doPreprocess($action)
	{
		if ($action === 'addlocations') {
			self::addLocations();
			return true;
		}
		return false;
	}

	public static function doRender($getAction)
	{
		if ($getAction === false) {
			if (User::hasPermission('location.view')) {
				// OK
			} elseif (User::hasPermission('subnets.edit')) {
				// Fallback to something else?
				Util::redirect('?do=locations&page=subnets');
			} else {
				// Trigger permission denied by asserting non-existent permission
				User::assertPermission('location.view');
			}
		}
		if ($getAction === false) {
			self::showLocationList();
			return true;
		}
		return false;
	}

	public static function doAjax($action)
	{
		return false;
	}

	private static function addLocations()
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

	public static function showLocationList()
	{
		// Warn admin about overlapping subnet definitions
		$overlapSelf = $overlapOther = true;
		LocationUtil::getOverlappingSubnets($overlapSelf, $overlapOther);
		// Find machines assigned to a room with a UUID mismatch
		$mismatchMachines = LocationUtil::getMachinesWithLocationMismatch(0, true);
		$locationList = Location::getLocationsAssoc();
		unset($locationList[0]);
		// Statistics: Count machines for each subnet
		$unassigned = false;
		$unassignedLoad = 0;
		$unassignedOverrides = 0;

		$allowedLocationIds = User::getAllowedLocations("location.view");
		foreach (array_keys($locationList) as $lid) {
			if (!User::hasPermission('.baseconfig.view', $lid)) {
				$locationList[$lid]['havebaseconfig'] = false;
			}
			if (!User::hasPermission('.sysconfig.config.view-list', $lid)) {
				$locationList[$lid]['havesysconfig'] = false;
			}
			if (!User::hasPermission('.statistics.view.list', $lid)) {
				$locationList[$lid]['havestatistics'] = false;
			}
			if (!User::hasPermission('.serversetup.ipxe.menu.assign', $lid)) {
				$locationList[$lid]['haveipxe'] = false;
			}
			if (!in_array($lid, $allowedLocationIds)) {
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
			$res = Database::simpleQuery("SELECT m.locationid, Count(*) AS cnt, Sum(If(m.state = 'OCCUPIED', 1, 0)) AS used
 				 FROM machine m WHERE (locationid IN (:allowedLocationIds) $extra) GROUP BY locationid", compact('allowedLocationIds'));
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
			$res = Database::simpleQuery("SELECT m.locationid, Count(DISTINCT sm.machineuuid) AS cnt FROM setting_machine sm
				INNER JOIN machine m USING (machineuuid) GROUP BY m.locationid");
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$locId = (int)$row['locationid'];
				if (isset($locationList[$locId])) {
					$locationList[$locId]['machineVarsOverrideCount'] = $row['cnt'];
				} else {
					$unassignedOverrides += $row['cnt'];
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
			self::propagateFields($locationList, $defaultConfig, 'configName', 'configClass');
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
		if (Module::isAvailable('serversetup') && class_exists('IPxe')) {
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
			self::propagateFields($locationList, '', 'customMenu', 'customMenuClass');
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
			'haveipxe' => Module::isAvailable('serversetup') && class_exists('IPxe'),
			'overlapSelf' => $overlapSelf,
			'overlapOther' => $overlapOther,
			'mismatchMachines' => $mismatchMachines,
			'unassignedCount' => $unassigned,
			'unassignedLoad' => ($unassigned ? (round(($unassignedLoad / $unassigned) * 100) . ' %') : ''),
			'unassignedOverrides' => $unassignedOverrides,
			'defaultConfig' => $defaultConfig,
			'addAllowedList' => array_values($addAllowedList),
		);
		// TODO: Buttons for config vars and sysconfig are currently always shown, as their availability
		// depends on permissions in the according modules, not this one
		Permission::addGlobalTags($data['perms'], NULL, ['subnets.edit', 'location.add']);
		Render::addTemplate('locations', $data);
		Module::isAvailable('js_ip'); // For CIDR magic
	}

	private static function propagateFields(&$locationList, $defaultValue, $name, $class)
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