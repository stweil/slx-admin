<?php

class SubPage
{

	private static $STATS_COLORS;

	public static function doPreprocess()
	{
		User::assertPermission('view.summary');
	}

	public static function doRender()
	{
		$sortColumn = Request::any('sortColumn');
		$sortDirection = Request::any('sortDirection');

		$filters = StatisticsFilter::parseQuery(StatisticsFilter::getQuery());
		$filterSet = new StatisticsFilterSet($filters);
		$filterSet->setSort($sortColumn, $sortDirection);

		if (!$filterSet->setAllowedLocationsFromPermission('view.summary')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=main');
		}

		// Prepare chart colors
		self::$STATS_COLORS = [];
		for ($i = 0; $i < 10; ++$i) {
			self::$STATS_COLORS[] = '#55' . sprintf('%02s%02s', dechex((($i + 1) * ($i + 1)) / .3922), dechex(abs((5 - $i) * 51)));
		}

		$filterSet->filterNonClients();
		Render::openTag('div', array('class' => 'row'));
		StatisticsFilter::renderFilterBox('summary', $filterSet, StatisticsFilter::getQuery());
		self::showSummary($filterSet);
		self::showMemory($filterSet);
		self::showId44($filterSet);
		self::showKvmState($filterSet);
		self::showLatestMachines($filterSet);
		self::showSystemModels($filterSet);
		Render::closeTag('div');
	}

	/**
	 * @param \StatisticsFilterSet $filterSet
	 */
	private static function showSummary($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);
		$known = Database::queryFirst("SELECT Count(*) AS val FROM machine m $join WHERE $where", $args);
		$on = Database::queryFirst("SELECT Count(*) AS val FROM machine m $join WHERE state IN ('IDLE', 'OCCUPIED') AND ($where)", $args);
		$used = Database::queryFirst("SELECT Count(*) AS val FROM machine m $join WHERE state = 'OCCUPIED' AND ($where)", $args);
		$hdd = Database::queryFirst("SELECT Count(*) AS val FROM machine m $join WHERE badsectors >= 10 AND ($where)", $args);
		if ($on['val'] != 0) {
			$usedpercent = round($used['val'] / $on['val'] * 100);
		} else {
			$usedpercent = 0;
		}
		$data = array(
			'known' => $known['val'],
			'online' => $on['val'],
			'used' => $used['val'],
			'usedpercent' => $usedpercent,
			'badhdd' => $hdd['val'],
		);
		// Graph
		$cutoff = time() - 2 * 86400;
		$res = Database::simpleQuery("SELECT dateline, data FROM statistic WHERE typeid = '~stats' AND dateline > $cutoff ORDER BY dateline ASC");
		$labels = array();
		$points1 = array('data' => array(), 'label' => 'Online', 'fillColor' => '#efe', 'strokeColor' => '#aea', 'pointColor' => '#7e7', 'pointStrokeColor' => '#fff', 'pointHighlightFill' => '#fff', 'pointHighlightStroke' => '#7e7');
		$points2 = array('data' => array(), 'label' => 'In use', 'fillColor' => '#fee', 'strokeColor' => '#eaa', 'pointColor' => '#e77', 'pointStrokeColor' => '#fff', 'pointHighlightFill' => '#fff', 'pointHighlightStroke' => '#e77');
		$sum = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$x = explode('#', $row['data']);
			if ($sum === 0) {
				$labels[] = date('H:i', $row['dateline']);
			} else {
				$x[1] = max($x[1], array_pop($points1['data']));
				$x[2] = max($x[2], array_pop($points2['data']));
			}
			$points1['data'][] = $x[1];
			$points2['data'][] = $x[2];
			++$sum;
			if ($sum === 12) {
				$sum = 0;
			}
		}
		$data['json'] = json_encode(array('labels' => $labels, 'datasets' => array($points1, $points2)));
		$data['query'] = StatisticsFilter::getQuery();
		if (Module::get('runmode') !== false) {
			$res = Database::queryFirst('SELECT Count(*) AS cnt FROM runmode');
			$data['runmode'] =  $res['cnt'];
		}
		// Draw
		Render::addTemplate('summary', $data);
	}

	/**
	 * @param \StatisticsFilterSet $filterSet
	 */
	private static function showSystemModels($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);
		$res = Database::simpleQuery('SELECT systemmodel, Round(AVG(realcores)) AS cores, Count(*) AS `count` FROM machine m'
			. " $join WHERE $where GROUP BY systemmodel ORDER BY `count` DESC, systemmodel ASC", $args);
		$lines = array();
		$json = array();
		$id = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (empty($row['systemmodel'])) {
				continue;
			}
			settype($row['count'], 'integer');
			$row['id'] = 'systemid' . $id;
			$row['urlsystemmodel'] = urlencode($row['systemmodel']);
			$lines[] = $row;
			$json[] = array(
				'color' => self::$STATS_COLORS[$id % count(self::$STATS_COLORS)],
				'label' => 'systemid' . $id,
				'value' => $row['count'],
			);
			++$id;
		}
		self::capChart($json, $lines, 0.92);
		Render::addTemplate('cpumodels', array('rows' => $lines, 'query' => StatisticsFilter::getQuery(), 'json' => json_encode($json)));
	}

	/**
	 * @param \StatisticsFilterSet $filterSet
	 */
	private static function showMemory($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);
		$res = Database::simpleQuery("SELECT mbram, Count(*) AS `count` FROM machine m $join WHERE $where  GROUP BY mbram", $args);
		$lines = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$gb = (int)ceil($row['mbram'] / 1024);
			for ($i = 1; $i < count(StatisticsFilter::SIZE_RAM); ++$i) {
				if (StatisticsFilter::SIZE_RAM[$i] < $gb) {
					continue;
				}
				if (StatisticsFilter::SIZE_RAM[$i] - $gb >= $gb - StatisticsFilter::SIZE_RAM[$i - 1]) {
					--$i;
				}
				$gb = StatisticsFilter::SIZE_RAM[$i];
				break;
			}
			if (isset($lines[$gb])) {
				$lines[$gb] += $row['count'];
			} else {
				$lines[$gb] = $row['count'];
			}
		}
		asort($lines);
		$data = array('rows' => array());
		$json = array();
		$id = 0;
		foreach (array_reverse($lines, true) as $k => $v) {
			$data['rows'][] = array('gb' => $k, 'count' => $v, 'class' => StatisticsStyling::ramColorClass($k * 1024));
			$json[] = array(
				'color' => self::$STATS_COLORS[$id % count(self::$STATS_COLORS)],
				'label' => (string)$k,
				'value' => $v,
			);
			++$id;
		}
		self::capChart($json, $data['rows'], 0.92);
		$data['json'] = json_encode($json);
		$data['query'] = StatisticsFilter::getQuery();
		Render::addTemplate('memory', $data);
	}

	/**
	 * @param \StatisticsFilterSet $filterSet
	 */
	private static function showKvmState($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);
		$colors = array('UNKNOWN' => '#666', 'UNSUPPORTED' => '#ea5', 'DISABLED' => '#e55', 'ENABLED' => '#6d6');
		$res = Database::simpleQuery("SELECT kvmstate, Count(*) AS `count` FROM machine m $join WHERE $where GROUP BY kvmstate ORDER BY `count` DESC", $args);
		$lines = array();
		$json = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$lines[] = $row;
			$json[] = array(
				'color' => isset($colors[$row['kvmstate']]) ? $colors[$row['kvmstate']] : '#000',
				'label' => $row['kvmstate'],
				'value' => $row['count'],
			);
		}
		Render::addTemplate('kvmstate', array('rows' => $lines, 'query' => StatisticsFilter::getQuery(),'json' => json_encode($json)));
	}

	/**
	 * @param \StatisticsFilterSet $filterSet
	 */
	private static function showId44($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);
		$res = Database::simpleQuery("SELECT id44mb, Count(*) AS `count` FROM machine m $join WHERE $where GROUP BY id44mb", $args);
		$lines = array();
		$total = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$total += $row['count'];
			$gb = (int)ceil($row['id44mb'] / 1024);
			for ($i = 1; $i < count(StatisticsFilter::SIZE_ID44); ++$i) {
				if (StatisticsFilter::SIZE_ID44[$i] < $gb) {
					continue;
				}
				if (StatisticsFilter::SIZE_ID44[$i] - $gb >= $gb - StatisticsFilter::SIZE_ID44[$i - 1]) {
					--$i;
				}
				$gb = StatisticsFilter::SIZE_ID44[$i];
				break;
			}
			if (isset($lines[$gb])) {
				$lines[$gb] += $row['count'];
			} else {
				$lines[$gb] = $row['count'];
			}
		}
		asort($lines);
		$data = array('rows' => array());
		$json = array();
		$id = 0;
		foreach (array_reverse($lines, true) as $k => $v) {
			$data['rows'][] = array('gb' => $k, 'count' => $v, 'class' => StatisticsStyling::hddColorClass($k));
			if ($k === 0) {
				$color = '#e55';
			} else {
				$color = self::$STATS_COLORS[$id++ % count(self::$STATS_COLORS)];
			}
			$json[] = array(
				'color' => $color,
				'label' => (string)$k,
				'value' => $v,
			);
		}
		self::capChart($json, $data['rows'], 0.95);
		$data['json'] = json_encode($json);
		$data['query'] = StatisticsFilter::getQuery();
		Render::addTemplate('id44', $data);
	}

	/**
	 * @param \StatisticsFilterSet $filterSet
	 */
	private static function showLatestMachines($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);
		$args['cutoff'] = ceil(time() / 3600) * 3600 - 86400 * 10;

		$res = Database::simpleQuery("SELECT machineuuid, clientip, hostname, firstseen, mbram, kvmstate, id44mb FROM machine m $join"
			. " WHERE firstseen > :cutoff AND $where ORDER BY firstseen DESC LIMIT 32", $args);
		$rows = array();
		$count = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (empty($row['hostname'])) {
				$row['hostname'] = $row['clientip'];
			}
			$row['firstseen_int'] = $row['firstseen'];
			$row['firstseen'] = Util::prettyTime($row['firstseen']);
			$row['gbram'] = round(round($row['mbram'] / 500) / 2, 1); // Trial and error until we got "expected" rounding..
			$row['gbtmp'] = round($row['id44mb'] / 1024);
			$row['ramclass'] = StatisticsStyling::ramColorClass($row['mbram']);
			$row['kvmclass'] = StatisticsStyling::kvmColorClass($row['kvmstate']);
			$row['hddclass'] = StatisticsStyling::hddColorClass($row['gbtmp']);
			$row['kvmicon'] = $row['kvmstate'] === 'ENABLED' ? '✓' : '✗';
			if (++$count > 5) {
				$row['collapse'] = 'collapse';
			}
			$rows[] = $row;
		}
		Render::addTemplate('newclients', array('rows' => $rows, 'openbutton' => $count > 5));
	}

	/*
	 * HELPERS
	 */



	private static function capChart(&$json, &$rows, $cutoff, $minSlice = 0.015)
	{
		$total = 0;
		foreach ($json as $entry) {
			$total += $entry['value'];
		}
		if ($total === 0) {
			return;
		}
		$cap = ceil($total * $cutoff);
		$accounted = 0;
		$id = 0;
		foreach ($json as $entry) {
			if (($accounted >= $cap || $entry['value'] / $total < $minSlice) && $id >= 3) {
				break;
			}
			++$id;
			$accounted += $entry['value'];
		}
		for ($i = $id; $i < count($rows); ++$i) {
			$rows[$i]['collapse'] = 'collapse';
		}
		$json = array_slice($json, 0, $id);
		if ($accounted / $total < 0.99) {
			$json[] = array(
				'color' => '#eee',
				'label' => 'invalid',
				'value' => ($total - $accounted),
			);
		}
	}

}