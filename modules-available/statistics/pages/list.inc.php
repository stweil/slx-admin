<?php

class SubPage
{

	public static function doPreprocess()
	{
		User::assertPermission('view.list');
	}

	public static function doRender()
	{
		$sortColumn = Request::any('sortColumn');
		$sortDirection = Request::any('sortDirection');

		$filters = StatisticsFilter::parseQuery(StatisticsFilter::getQuery());
		$filterSet = new StatisticsFilterSet($filters);
		$filterSet->setSort($sortColumn, $sortDirection);

		if (!$filterSet->setAllowedLocationsFromPermission('view.list')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=main');
		}
		Render::openTag('div', array('class' => 'row'));
		StatisticsFilter::renderFilterBox('list', $filterSet, StatisticsFilter::getQuery());
		Render::closeTag('div');
		self::showMachineList($filterSet);
	}


	/**
	 * @param \StatisticsFilterSet $filterSet
	 */
	private static function showMachineList($filterSet)
	{
		Module::isAvailable('js_stupidtable');
		$filterSet->makeFragments($where, $join, $sort, $args);
		$xtra = '';
		if ($filterSet->isNoId44Filter()) {
			$xtra .= ', data';
		}
		if (Module::isAvailable('runmode')) {
			$xtra .= ', runmode.module AS rmmodule, runmode.isclient';
			if (strpos($join, 'runmode') === false) {
				$join .= ' LEFT JOIN runmode USING (machineuuid) ';
			}
		}
		$res = Database::simpleQuery("SELECT m.machineuuid, m.locationid, m.macaddr, m.clientip, m.lastseen,
			m.logintime, m.state, m.realcores, m.mbram, m.kvmstate, m.cpumodel, m.id44mb, m.hostname, m.notes IS NOT NULL AS hasnotes,
			m.badsectors, Count(s.machineuuid) AS confvars $xtra FROM machine m
			LEFT JOIN setting_machine s USING (machineuuid)
			$join WHERE $where GROUP BY m.machineuuid $sort", $args);
		$rows = array();
		$singleMachine = 'none';
		// TODO: Cannot disable checkbox for those where user has no permission, since we got multiple actions now
		// We should pass these lists to the output and add some JS magic
		// Either disable the delete/reboot/... buttons as soon as at least one "forbidden" client is selected (potentially annoying)
		// or add a notice to the confirmation dialog of the according action (nicer but a little more work)
		$deleteAllowedLocations = User::getAllowedLocations("machine.delete");
		$rebootAllowedLocations = User::getAllowedLocations('.rebootcontrol.action.reboot');
		$shutdownAllowedLocations = User::getAllowedLocations('.rebootcontrol.action.reboot');
		$wolAllowedLocations = User::getAllowedLocations('.rebootcontrol.action.wol');
		$execAllowedLocations = User::getAllowedLocations('.rebootcontrol.action.exec');
		// Only make client clickable if user is allowed to view details page
		$detailsAllowedLocations = User::getAllowedLocations("machine.view-details");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($singleMachine === 'none') {
				$singleMachine = $row['machineuuid'];
			} else {
				$singleMachine = false;
			}
			$row['link_details'] = in_array($row['locationid'], $detailsAllowedLocations);
			//$row['firstseen'] = Util::prettyTime($row['firstseen']);
			$row['lastseen_int'] = $row['lastseen'];
			$row['lastseen'] = Util::prettyTime($row['lastseen']);
			//$row['lastboot'] = Util::prettyTime($row['lastboot']);
			$row['gbram'] = round(ceil($row['mbram'] / 512) / 2, 1); // Trial and error until we got "expected" rounding..
			$row['gbtmp'] = round($row['id44mb'] / 1024);
			$octets = explode('.', $row['clientip']);
			if (count($octets) === 4) {
				$row['subnet'] = "$octets[0].$octets[1].$octets[2].";
				$row['lastoctet'] = $octets[3];
			}
			$row['ramclass'] = StatisticsStyling::ramColorClass($row['mbram']);
			$row['kvmclass'] = StatisticsStyling::kvmColorClass($row['kvmstate']);
			$row['hddclass'] = StatisticsStyling::hddColorClass($row['gbtmp']);
			if (empty($row['hostname'])) {
				$row['hostname'] = $row['clientip'];
			}
			if (isset($row['data'])) {
				if (!preg_match('/^(Disk.* bytes|Disk.*\d{5,} sectors)/m', $row['data'])) {
					$row['nohdd'] = true;
				}
			}
			$row['cpumodel'] = preg_replace('/\(R\)|\(TM\)|\bintel\b|\bamd\b|\bcpu\b|dual-core|\bdual\s+core\b|\bdual\b|\bprocessor\b/i', ' ', $row['cpumodel']);
			if (!empty($row['rmmodule'])) {
				$data = RunMode::getRunMode($row['machineuuid'], RunMode::DATA_STRINGS);
				if ($data !== false) {
					$row['moduleName'] = $data['moduleName'];
					$row['modeName'] = $data['modeName'];
				}
				if (!$row['isclient'] && $row['state'] === 'IDLE') {
					$row['state'] = 'OCCUPIED';
				}
			}
			$row['state_' . $row['state']] = true;
			$row['locationname'] = Location::getName($row['locationid']);
			$rows[] = $row;
		}
		if ($singleMachine !== false && $singleMachine !== 'none') {
			Util::redirect('?do=statistics&uuid=' . $singleMachine);
		}
		$data = array(
			'rowCount' => count($rows),
			'rows' => $rows,
			'query' => StatisticsFilter::getQuery(),
			'delimiter' => StatisticsFilter::DELIMITER,
			'sortDirection' => $filterSet->getSortDirection(),
			'sortColumn' => $filterSet->getSortColumn(),
			'columns' => json_encode(StatisticsFilter::$columns),
			'showList' => 1,
			'show' => 'list',
			'redirect' => $_SERVER['QUERY_STRING'],
			'rebootcontrol' => (Module::get('rebootcontrol') !== false),
			'canReboot' => !empty($rebootAllowedLocations),
			'canShutdown' => !empty($shutdownAllowedLocations),
			'canDelete' => !empty($deleteAllowedLocations),
			'canWol' => !empty($wolAllowedLocations),
			'canExec' => !empty($execAllowedLocations),
		);
		Render::addTemplate('clientlist', $data);
	}

}
