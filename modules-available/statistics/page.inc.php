<?php

global $STATS_COLORS, $SIZE_ID44, $SIZE_RAM;
global $unique_key;

$STATS_COLORS = array();
for ($i = 0; $i < 10; ++$i) {
	$STATS_COLORS[] = '#55' . sprintf('%02s%02s', dechex((($i + 1) * ($i + 1)) / .3922), dechex(abs((5 - $i) * 51)));
}
//$STATS_COLORS = array('#57e', '#ee8', '#5ae', '#fb7', '#6d7', '#e77', '#3af', '#666', '#e0e', '#999');
$SIZE_ID44 = array(0, 8, 16, 24, 30, 40, 50, 60, 80, 100, 120, 150, 180, 250, 300, 350, 400, 450, 500);
$SIZE_RAM = array(1, 2, 3, 4, 6, 8, 10, 12, 16, 24, 32, 48, 64, 96, 128, 192, 256, 320, 480, 512, 768, 1024);

class Page_Statistics extends Page
{
	/* some constants, TODO: Find a better place */
	public static $op_nominal;
	public static $op_ordinal;
	public static $op_stringcmp;
	public static $columns;

	private $query;

	/* PHP sucks, no static, const array definitions... Or am I missing something? */
	public function initConstants()
	{
		Page_Statistics::$op_nominal = ['!=', '='];
		Page_Statistics::$op_ordinal = ['!=', '<=', '>=', '=', '<', '>'];
		Page_Statistics::$op_stringcmp = ['!~', '~', '=', '!='];

		Page_Statistics::$columns = [
			'machineuuid' => [
				'op' => Page_Statistics::$op_nominal,
				'type' => 'string',
				'column' => true,
			],
			'macaddr' => [
				'op' => Page_Statistics::$op_nominal,
				'type' => 'string',
				'column' => true,
			],
			'firstseen' => [
				'op' => Page_Statistics::$op_ordinal,
				'type' => 'date',
				'column' => true,
			],
			'lastseen' => [
				'op' => Page_Statistics::$op_ordinal,
				'type' => 'date',
				'column' => true,
			],
			'logintime' => [
				'op' => Page_Statistics::$op_ordinal,
				'type' => 'date',
				'column' => true,
			],
			'realcores' => [
				'op' => Page_Statistics::$op_ordinal,
				'type' => 'int',
				'column' => true,
			],
			'systemmodel' => [
				'op' => Page_Statistics::$op_stringcmp,
				'type' => 'string',
				'column' => true,
			],
			'cpumodel' => [
				'op' => Page_Statistics::$op_stringcmp,
				'type' => 'string',
				'column' => true,
			],
			'hddgb' => [
				'op' => Page_Statistics::$op_ordinal,
				'type' => 'int',
				'column' => false,
				'map_sort' => 'id44mb'
			],
			'gbram' => [
				'op' => Page_Statistics::$op_ordinal,
				'type' => 'int',
				'map_sort' => 'mbram',
				'column' => false,
			],
			'kvmstate' => [
				'op' => Page_Statistics::$op_nominal,
				'type' => 'enum',
				'column' => true,
				'values' => ['ENABLED', 'DISABLED', 'UNSUPPORTED']
			],
			'badsectors' => [
				'op' => Page_Statistics::$op_ordinal,
				'type' => 'int',
				'column' => true
			],
			'clientip' => [
				'op' => Page_Statistics::$op_nominal,
				'type' => 'string',
				'column' => true
			],
			'subnet' => [
				'op' => Page_Statistics::$op_nominal,
				'type' => 'string',
				'column' => false
			],
			'currentuser' => [
				'op' => Page_Statistics::$op_nominal,
				'type' => 'string',
				'column' => true
			]
		];
		if (Module::isAvailable('locations')) {
			Page_Statistics::$columns['location'] = [
				'op' => Page_Statistics::$op_nominal,
				'type' => 'enum',
				'column' => false,
				'values' => array_keys(Location::getLocationsAssoc()),
			];
		}
		/* TODO ... */
	}

	/*
	 * TODO: Move to separate unit... hardware configurator?
	 */

	protected function handleProjector($action)
	{
		$hwid = Request::post('hwid', false, 'int');
		if ($hwid === false) {
			Util::traceError('Param hwid missing');
		}
		if ($action === 'addprojector') {
			Database::exec('INSERT INTO statistic_hw_prop (hwid, prop, value)'
				. ' VALUES (:hwid, :prop, :value)', array(
					'hwid' => $hwid,
					'prop' => 'projector',
					'value' => 'true',
			));
		} else {
			Database::exec('DELETE FROM statistic_hw_prop WHERE hwid = :hwid AND prop = :prop', array(
				'hwid' => $hwid,
				'prop' => 'projector',
			));
		}
		if (Module::isAvailable('sysconfig')) {
			ConfigTgz::rebuildAllConfigs();
		}
		Util::redirect('?do=statistics&show=projectors');
	}

	protected function showProjectors()
	{
		$res = Database::simpleQuery('SELECT h.hwname, h.hwid FROM statistic_hw h'
			. " INNER JOIN statistic_hw_prop p ON (h.hwid = p.hwid AND p.prop = :projector)"
			. " WHERE h.hwtype = :screen ORDER BY h.hwname ASC", array(
				'projector' => 'projector',
				'screen' => DeviceType::SCREEN,
		));
		$data = array(
			'projectors' => $res->fetchAll(PDO::FETCH_ASSOC)
		);
		Render::addTemplate('projector-list', $data);
	}

	/*
	 * End TODO
	 */

	protected function doPreprocess()
	{
		$this->initConstants();
		User::load();
		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}
		$action = Request::post('action');
		if ($action === 'setnotes') {
			$uuid = Request::post('uuid', '', 'string');
			$text = Request::post('content', '', 'string');
			if (empty($text)) {
				$text = null;
			}
			Database::exec('UPDATE machine SET notes = :text WHERE machineuuid = :uuid', array(
				'uuid' => $uuid,
				'text' => $text,
			));
			Message::addSuccess('notes-saved');
			Util::redirect('?do=Statistics&uuid=' . $uuid);
		} elseif ($action === 'addprojector' || $action === 'delprojector') {
			$this->handleProjector($action);
		}
		// Fix online state of machines that crashed -- TODO: Make cronjob for this
		Database::exec("UPDATE machine SET lastboot = 0 WHERE lastseen < UNIX_TIMESTAMP() - 610");
	}

	protected function doRender()
	{
		$uuid = Request::get('uuid', false, 'string');
		if ($uuid !== false) {
			$this->showMachine($uuid);

			return;
		}

		/* read filter */
		$this->query = Request::any('filters', false);
		if ($this->query === false) {
			$this->query = 'lastseen > ' . gmdate('Y-m-d', strtotime('-30 day'));
		}
		$sortColumn = Request::any('sortColumn');
		$sortDirection = Request::any('sortDirection');
		$filters = Filter::parseQuery($this->query);

		$filterSet = new FilterSet($filters);
		$filterSet->setSort($sortColumn, $sortDirection);


		$show = Request::get('show', 'stat', 'string');
		if ($show == 'list') {
			Render::openTag('div', array('class' => 'row'));
			$this->showFilter('list', $filterSet);
			Render::closeTag('div');
			$this->showMachineList($filterSet);
			return;
		} elseif ($show === 'projectors') {
			$this->showProjectors();
			return;
		}
		Render::openTag('div', array('class' => 'row'));
		$this->showFilter('stat', $filterSet);
		$this->showSummary($filterSet);
		$this->showMemory($filterSet);
		$this->showId44($filterSet);
		$this->showKvmState($filterSet);
		$this->showLatestMachines($filterSet);
		$this->showSystemModels($filterSet);
		Render::closeTag('div');
	}

	/**
	 * @param \FilterSet $filterSet
	 */
	private function showFilter($show, $filterSet)
	{
		$data =  array(
			'show' => $show,
			'query' => $this->query,
			'delimiter' => Filter::DELIMITER,
			'sortDirection' => $filterSet->getSortDirection(),
			'sortColumn' => $filterSet->getSortColumn(),
			'columns' => json_encode(Page_Statistics::$columns),
		);

		if ($show === 'list') {
			$data['listButtonClass'] = 'btn-primary';
			$data['statButtonClass'] = 'btn-default';
		} else {
			$data['listButtonClass'] = 'btn-default';
			$data['statButtonClass'] = 'btn-primary';
		}


		$locsFlat = array();
		if (Module::isAvailable('locations')) {
			foreach (Location::getLocations() as $loc) {
				$locsFlat['L' . $loc['locationid']] = array(
					'pad' => $loc['locationpad'],
					'name' => $loc['locationname']
				);
			}
		}

		$data['locations'] = json_encode($locsFlat);
		Render::addTemplate('filterbox', $data);


	}
	private function capChart(&$json, &$rows, $cutoff, $minSlice = 0.015)
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

	private function redirectFirst($where, $join, $args)
	{
		$res = Database::queryFirst("SELECT machineuuid FROM machine $join WHERE ($where) LIMIT 1", $args);
		if ($res !== false) {
			Util::redirect('?do=statistics&uuid=' . $res['machineuuid']);
		}
	}

	/**
	 * @param \FilterSet $filterSet
	 */
	private function showSummary($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);

		$known = Database::queryFirst("SELECT Count(*) AS val FROM machine $join WHERE ($where)", $args);
		// If we only have one machine, redirect to machine details
		if ($known['val'] == 1) {
			$this->redirectFirst($where, $join, $args);
		}
		$on = Database::queryFirst("SELECT Count(*) AS val FROM machine $join WHERE lastboot <> 0 AND ($where)", $args);
		$used = Database::queryFirst("SELECT Count(*) AS val FROM machine $join WHERE lastboot <> 0 AND logintime <> 0 AND ($where)", $args);
		$hdd = Database::queryFirst("SELECT Count(*) AS val FROM machine $join WHERE badsectors >= 10 AND ($where)", $args);
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
		$data['query'] = $this->query;
		// Draw
		Render::addTemplate('summary', $data);
	}

	/**
	 * @param \FilterSet $filterSet
	 */
	private function showSystemModels($filterSet)
	{
		global $STATS_COLORS;

		$filterSet->makeFragments($where, $join, $sort, $args);

		$res = Database::simpleQuery('SELECT systemmodel, Round(AVG(realcores)) AS cores, Count(*) AS `count` FROM machine'
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
				'color' => $STATS_COLORS[$id % count($STATS_COLORS)],
				'label' => 'systemid' . $id,
				'value' => $row['count'],
			);
			++$id;
		}
		$this->capChart($json, $lines, 0.92);
		Render::addTemplate('cpumodels', array('rows' => $lines, 'query' => $this->query, 'json' => json_encode($json)));
	}

	/**
	 * @param \FilterSet $filterSet
	 */
	private function showMemory($filterSet)
	{
		global $STATS_COLORS, $SIZE_RAM;

		$filterSet->makeFragments($where, $join, $sort, $args);

		$res = Database::simpleQuery("SELECT mbram, Count(*) AS `count` FROM machine $join WHERE $where  GROUP BY mbram", $args);
		$lines = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$gb = (int)ceil($row['mbram'] / 1024);
			for ($i = 1; $i < count($SIZE_RAM); ++$i) {
				if ($SIZE_RAM[$i] < $gb) {
					continue;
				}
				if ($SIZE_RAM[$i] - $gb >= $gb - $SIZE_RAM[$i - 1]) {
					--$i;
				}
				$gb = $SIZE_RAM[$i];
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
			$data['rows'][] = array('gb' => $k, 'count' => $v, 'class' => $this->ramColorClass($k * 1024));
			$json[] = array(
				'color' => $STATS_COLORS[$id % count($STATS_COLORS)],
				'label' => (string)$k,
				'value' => $v,
			);
			++$id;
		}
		$this->capChart($json, $data['rows'], 0.92);
		$data['json'] = json_encode($json);
		$data['query'] = $this->query;
		Render::addTemplate('memory', $data);
	}

	/**
	 * @param \FilterSet $filterSet
	 */
	private function showKvmState($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);

		$colors = array('UNKNOWN' => '#666', 'UNSUPPORTED' => '#ea5', 'DISABLED' => '#e55', 'ENABLED' => '#6d6');
		$res = Database::simpleQuery("SELECT kvmstate, Count(*) AS `count` FROM machine $join WHERE $where GROUP BY kvmstate ORDER BY `count` DESC", $args);
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
		Render::addTemplate('kvmstate', array('rows' => $lines, 'query' => $this->query,'json' => json_encode($json)));
	}

	/**
	 * @param \FilterSet $filterSet
	 */
	private function showId44($filterSet)
	{
		global $STATS_COLORS, $SIZE_ID44;

		$filterSet->makeFragments($where, $join, $sort, $args);

		$res = Database::simpleQuery("SELECT id44mb, Count(*) AS `count` FROM machine $join WHERE $where GROUP BY id44mb", $args);
		$lines = array();
		$total = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$total += $row['count'];
			$gb = (int)ceil($row['id44mb'] / 1024);
			for ($i = 1; $i < count($SIZE_ID44); ++$i) {
				if ($SIZE_ID44[$i] < $gb) {
					continue;
				}
				if ($SIZE_ID44[$i] - $gb >= $gb - $SIZE_ID44[$i - 1]) {
					--$i;
				}
				$gb = $SIZE_ID44[$i];
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
			$data['rows'][] = array('gb' => $k, 'count' => $v, 'class' => $this->hddColorClass($k));
			if ($k === 0) {
				$color = '#e55';
			} else {
				$color = $STATS_COLORS[$id++ % count($STATS_COLORS)];
			}
			$json[] = array(
				'color' => $color,
				'label' => (string)$k,
				'value' => $v,
			);
		}
		$this->capChart($json, $data['rows'], 0.95);
		$data['json'] = json_encode($json);
		$data['query'] = $this->query;
		Render::addTemplate('id44', $data);
	}

	/**
	 * @param \FilterSet $filterSet
	 */
	private function showLatestMachines($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);

		$args['cutoff'] = ceil(time() / 3600) * 3600 - 86400 * 10;

		$res = Database::simpleQuery("SELECT machineuuid, clientip, hostname, firstseen, mbram, kvmstate, id44mb FROM machine $join"
			. " WHERE firstseen > :cutoff AND $where ORDER BY firstseen DESC LIMIT 32", $args);
		$rows = array();
		$count = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (empty($row['hostname'])) {
				$row['hostname'] = $row['clientip'];
			}
			$row['firstseen'] = date('d.m. H:i', $row['firstseen']);
			$row['gbram'] = round(round($row['mbram'] / 500) / 2, 1); // Trial and error until we got "expected" rounding..
			$row['gbtmp'] = round($row['id44mb'] / 1024);
			$row['ramclass'] = $this->ramColorClass($row['mbram']);
			$row['kvmclass'] = $this->kvmColorClass($row['kvmstate']);
			$row['hddclass'] = $this->hddColorClass($row['gbtmp']);
			$row['kvmicon'] = $row['kvmstate'] === 'ENABLED' ? '✓' : '✗';
			if (++$count > 5) {
				$row['collapse'] = 'collapse';
			}
			$rows[] = $row;
		}
		Render::addTemplate('newclients', array('rows' => $rows, 'openbutton' => $count > 5));
	}

	/**
	 * @param \FilterSet $filterSet
	 */
	private function showMachineList($filterSet)
	{
		$filterSet->makeFragments($where, $join, $sort, $args);

		$xtra = '';
		if ($filterSet->isNoId44Filter()) {
			$xtra = ', data';
		}
		$res = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, firstseen, lastseen,'
			. ' logintime, lastboot, realcores, mbram, kvmstate, cpumodel, id44mb, hostname, notes IS NOT NULL AS hasnotes,'
			. ' badsectors ' . $xtra . ' FROM machine'
			. " $join WHERE $where $sort", $args);
		$rows = array();
		$NOW = time();
		$singleMachine = 'none';
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($singleMachine === 'none') {
				$singleMachine = $row['machineuuid'];
			} else {
				$singleMachine = false;
			}
			if ($row['lastboot'] == 0) {
				$row['state_off'] = true;
			} elseif ($row['logintime'] == 0) {
				$row['state_idle'] = true;
			} else {
				$row['state_occupied'] = true;
			}
			//$row['firstseen'] = date('d.m.Y H:i', $row['firstseen']);
			$row['lastseen'] = date('d.m. H:i', $row['lastseen']);
			//$row['lastboot'] = date('d.m. H:i', $row['lastboot']);
			$row['gbram'] = round(round($row['mbram'] / 500) / 2, 1); // Trial and error until we got "expected" rounding..
			$row['gbtmp'] = round($row['id44mb'] / 1024);
			$octets = explode('.', $row['clientip']);
			if (count($octets) === 4) {
				$row['subnet'] = "$octets[0].$octets[1].$octets[2].";
				$row['lastoctet'] = $octets[3];
			}
			$row['ramclass'] = $this->ramColorClass($row['mbram']);
			$row['kvmclass'] = $this->kvmColorClass($row['kvmstate']);
			$row['hddclass'] = $this->hddColorClass($row['gbtmp']);
			if (empty($row['hostname'])) {
				$row['hostname'] = $row['clientip'];
			}
			if (isset($row['data'])) {
				if (!preg_match('/^(Disk.* bytes|Disk.*\d{5,} sectors)/m', $row['data'])) {
					$row['nohdd'] = true;
				}
			}
			$rows[] = $row;
		}
		if ($singleMachine !== false && $singleMachine !== 'none') {
			Util::redirect('?do=statistics&uuid=' . $singleMachine);
		}
		Render::addTemplate('clientlist', array(
			'rowCount' => count($rows),
			'rows' => $rows,
			'query' => $this->query,
			'delimiter' => Filter::DELIMITER,
			'sortDirection' => $filterSet->getSortDirection(),
			'sortColumn' => $filterSet->getSortColumn(),
			'columns' => json_encode(Page_Statistics::$columns),
			'showList' => 1,
			'show' => 'list'
		));
	}

	private function ramColorClass($mb)
	{
		if ($mb < 1500) {
			return 'danger';
		}
		if ($mb < 2500) {
			return 'warning';
		}

		return '';
	}

	private function kvmColorClass($state)
	{
		if ($state === 'DISABLED') {
			return 'danger';
		}
		if ($state === 'UNKNOWN' || $state === 'UNSUPPORTED') {
			return 'warning';
		}

		return '';
	}

	private function hddColorClass($gb)
	{
		if ($gb < 7) {
			return 'danger';
		}
		if ($gb < 25) {
			return 'warning';
		}

		return '';
	}

	public static function findBestValue($array, $value, $up)
	{
		$best = 0;
		for ($i = 0; $i < count($array); ++$i) {
			if (abs($array[$i] - $value) < abs($array[$best] - $value)) {
				$best = $i;
			}
		}
		if (!$up && $best === 0) {
			return $array[0];
		}
		if ($up && $best + 1 === count($array)) {
			return $array[$best];
		}
		if ($up) {
			return ($array[$best] + $array[$best + 1]) / 2;
		}

		return ($array[$best] + $array[$best - 1]) / 2;
	}

	private function fillSessionInfo(&$row)
	{
		if (!empty($row['currentuser'])) {
			$row['username'] = $row['currentuser'];
			if (strlen($row['currentsession']) === 36 && Module::isAvailable('dozmod')) {
				$lecture = Database::queryFirst("SELECT lectureid, displayname FROM sat.lecture WHERE lectureid = :lectureid",
					array('lectureid' => $row['currentsession']));
				if ($lecture !== false) {
					$row['currentsession'] = $lecture['displayname'];
					$row['lectureid'] = $lecture['lectureid'];
				}
			}
			$row['session'] = $row['currentsession'];
			return;
		}
		$res = Database::simpleQuery('SELECT dateline, username, data FROM statistic'
			. " WHERE clientip = :ip AND typeid = '.vmchooser-session-name'"
			. ' AND dateline BETWEEN :start AND :end', array(
			'ip' => $row['clientip'],
			'start' => $row['logintime'] - 60,
			'end' => $row['logintime'] + 300,
		));
		$session = false;
		while ($r = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($session === false || abs($session['dateline'] - $row['logintime']) > abs($r['dateline'] - $row['logintime'])) {
				$session = $r;
			}
		}
		if ($session !== false) {
			$row['session'] = $session['data'];
			$row['username'] = $session['username'];
		}
	}

	private function showMachine($uuid)
	{
		$client = Database::queryFirst('SELECT machineuuid, locationid, macaddr, clientip, firstseen, lastseen, logintime, lastboot,'
			. ' mbram, kvmstate, cpumodel, id44mb, data, hostname, currentuser, currentsession, notes FROM machine WHERE machineuuid = :uuid',
			array('uuid' => $uuid));
		// Hack: Get raw collected data
		if (Request::get('raw', false)) {
			Header('Content-Type: text/plain; charset=utf-8');
			die($client['data']);
		}
		// Mangle fields
		$NOW = time();
		if ($client['lastboot'] == 0) {
			$client['state_off'] = true;
		} elseif ($client['logintime'] == 0) {
			$client['state_idle'] = true;
		} else {
			$client['state_occupied'] = true;
			$this->fillSessionInfo($client);
		}
		$client['firstseen_s'] = date('d.m.Y H:i', $client['firstseen']);
		$client['lastseen_s'] = date('d.m.Y H:i', $client['lastseen']);
		if ($client['lastboot'] == 0) {
			$client['lastboot_s'] = '-';
		} else {
			$uptime = $NOW - $client['lastboot'];
			$client['lastboot_s'] = date('d.m.Y H:i', $client['lastboot']);
			if (!isset($client['state_off']) || !$client['state_off']) {
				$client['lastboot_s'] .= ' (Up ' . floor($uptime / 86400) . 'd ' . gmdate('H:i', $uptime) . ')';
			}
		}
		$client['logintime_s'] = date('d.m.Y H:i', $client['logintime']);
		$client['gbram'] = round(round($client['mbram'] / 500) / 2, 1);
		$client['gbtmp'] = round($client['id44mb'] / 1024);
		$client['ramclass'] = $this->ramColorClass($client['mbram']);
		$client['kvmclass'] = $this->kvmColorClass($client['kvmstate']);
		$client['hddclass'] = $this->hddColorClass($client['gbtmp']);
		// Parse the giant blob of data
		if (strpos($client['data'], "\r") !== false) {
			$client['data'] = str_replace("\r", "\n", $client['data']);
		}
		$hdds = array();
		if (preg_match_all('/##### ([^#]+) #+$(.*?)^#####/ims', $client['data'] . '########', $out, PREG_SET_ORDER)) {
			foreach ($out as $section) {
				if ($section[1] === 'CPU') {
					Parser::parseCpu($client, $section[2]);
				}
				if ($section[1] === 'dmidecode') {
					Parser::parseDmiDecode($client, $section[2]);
				}
				if ($section[1] === 'Partition tables') {
					Parser::parseHdd($hdds, $section[2]);
				}
				if ($section[1] === 'PCI ID') {
					$client['lspci1'] = $client['lspci2'] = array();
					Parser::parsePci($client['lspci1'], $client['lspci2'], $section[2]);
				}
				if (isset($hdds['hdds']) && $section[1] === 'smartctl') {
					// This currently required that the partition table section comes first...
					Parser::parseSmartctl($hdds['hdds'], $section[2]);
				}
			}
		}
		unset($client['data']);
		// Get locations
		if (Module::isAvailable('locations')) {
			$locs = Location::getLocationsAssoc();
			$next = (int)$client['locationid'];
			$output = array();
			while (isset($locs[$next])) {
				array_unshift($output, $locs[$next]);
				$next = $locs[$next]['parentlocationid'];
			}
			$client['locations'] = $output;
		}
		// Screens TODO Move everything else to hw table instead of blob parsing above
		// `devicetype`, `devicename`, `subid`, `machineuuid`
		$res = Database::simpleQuery("SELECT m.hwid, h.hwname, m.devpath AS connector, m.disconnecttime,"
			. " p.value AS resolution, q.prop AS projector FROM machine_x_hw m"
			. " INNER JOIN statistic_hw h ON (m.hwid = h.hwid AND h.hwtype = :screen)"
			. " LEFT JOIN machine_x_hw_prop p ON (m.machinehwid = p.machinehwid AND p.prop = 'resolution')"
			. " LEFT JOIN statistic_hw_prop q ON (m.hwid = q.hwid AND q.prop = 'projector')"
			. " WHERE m.machineuuid = :uuid",
			array('screen' => DeviceType::SCREEN, 'uuid' => $uuid));
		$client['screens'] = array();
		$ports = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['disconnecttime'] != 0)
				continue;
			$ports[] = $row['connector'];
			$client['screens'][] = $row;
		}
		array_multisort($ports, SORT_ASC, $client['screens']);
		// Throw output at user
		Render::addTemplate('machine-main', $client);
		// Sessions
		$NOW = time();
		$cutoff = $NOW - 86400 * 7;
		//if ($cutoff < $client['firstseen']) $cutoff = $client['firstseen'];
		$scale = 100 / ($NOW - $cutoff);
		$res = Database::simpleQuery('SELECT dateline, typeid, data FROM statistic'
			. " WHERE dateline > :cutoff AND typeid IN ('~session-length', '~offline-length') AND machineuuid = :uuid ORDER BY dateline ASC", array(
			'cutoff' => $cutoff - 86400 * 14,
			'uuid' => $uuid,
		));
		$spans['rows'] = array();
		$spans['graph'] = '';
		$last = false;
		$first = true;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($first && $row['dateline'] > $cutoff && $client['lastboot'] > $cutoff) {
				// Special case: offline before
				$spans['graph'] .= '<div style="background:#444;left:0%;width:' . round((min($row['dateline'], $client['lastboot']) - $cutoff) * $scale, 2) . '%">&nbsp;</div>';
			}
			$first = false;
			if ($row['dateline'] + $row['data'] < $cutoff || $row['data'] > 864000) {
				continue;
			}
			if ($last !== false && abs($last['dateline'] - $row['dateline']) < 30
				&& abs($last['data'] - $row['data']) < 30
			) {
				continue;
			}
			if ($last !== false && $last['dateline'] + $last['data'] > $row['dateline']) {
				$point = $last['dateline'] + $last['data'];
				$row['data'] -= ($point - $row['dateline']);
				$row['dateline'] = $point;
			}
			if ($row['dateline'] < $cutoff) {
				$row['data'] -= ($cutoff - $row['dateline']);
				$row['dateline'] = $cutoff;
			}
			$row['from'] = date('d.m. H:i', $row['dateline']);
			$row['duration'] = floor($row['data'] / 86400) . 'd ' . gmdate('H:i', $row['data']);
			if ($row['typeid'] === '~offline-length') {
				$row['glyph'] = 'off';
				$color = '#444';
			} else {
				$row['glyph'] = 'user';
				$color = '#e77';
			}
			$spans['graph'] .= '<div style="background:' . $color . ';left:' . round(($row['dateline'] - $cutoff) * $scale, 2) . '%;width:' . round(($row['data']) * $scale, 2) . '%">&nbsp;</div>';
			$spans['rows'][] = $row;
			$last = $row;
		}
		if ($first && $client['lastboot'] > $cutoff) {
			// Special case: offline before
			$spans['graph'] .= '<div style="background:#444;left:0%;width:' . round(($client['lastboot'] - $cutoff) * $scale, 2) . '%">&nbsp;</div>';
		}
		if (isset($client['state_occupied'])) {
			$spans['graph'] .= '<div style="background:#e99;left:' . round(($client['logintime'] - $cutoff) * $scale, 2) . '%;width:' . round(($NOW - $client['logintime'] + 900) * $scale, 2) . '%">&nbsp;</div>';
		} elseif (isset($client['state_off'])) {
			$spans['graph'] .= '<div style="background:#444;left:' . round(($client['lastseen'] - $cutoff) * $scale, 2) . '%;width:' . round(($NOW - $client['lastseen'] + 900) * $scale, 2) . '%">&nbsp;</div>';
		}
		$t = explode('-', date('Y-n-j-G', $cutoff));
		if ($t[3] >= 8 && $t[3] <= 22) {
			$start = mktime(22, 0, 0, $t[1], $t[2], $t[0]);
		} else {
			$start = mktime(22, 0, 0, $t[1], $t[2] - 1, $t[0]);
		}
		for ($i = $start; $i < $NOW; $i += 86400) {
			$spans['graph'] .= '<div style="background:rgba(0,0,90,.2);left:' . round(($i - $cutoff) * $scale, 2) . '%;width:' . round((10 * 3600) * $scale, 2) . '%">&nbsp;</div>';
		}
		if (count($spans['rows']) > 10) {
			$spans['hasrows2'] = true;
			$spans['rows2'] = array_slice($spans['rows'], ceil(count($spans['rows']) / 2));
			$spans['rows'] = array_slice($spans['rows'], 0, ceil(count($spans['rows']) / 2));
		}
		Render::addTemplate('machine-usage', $spans);
		// Any hdds?
		if (!empty($hdds['hdds'])) {
			Render::addTemplate('machine-hdds', $hdds);
		}
		// Client log
		if (Module::get('syslog') !== false) {
			$lres = Database::simpleQuery('SELECT logid, dateline, logtypeid, clientip, description, extra FROM clientlog'
				. ' WHERE machineuuid = :uuid ORDER BY logid DESC LIMIT 25', array('uuid' => $client['machineuuid']));
			$today = date('d.m.Y');
			$yesterday = date('d.m.Y', time() - 86400);
			$count = 0;
			$log = array();
			while ($row = $lres->fetch(PDO::FETCH_ASSOC)) {
				if (substr($row['description'], -5) === 'on :0' && strpos($row['description'], 'root logged') === false) {
					continue;
				}
				$day = date('d.m.Y', $row['dateline']);
				if ($day === $today) {
					$day = Dictionary::translate('lang_today');
				} elseif ($day === $yesterday) {
					$day = Dictionary::translate('lang_yesterday');
				}
				$row['date'] = $day . date(' H:i', $row['dateline']);
				$row['icon'] = $this->eventToIconName($row['logtypeid']);
				$log[] = $row;
				if (++$count === 10) {
					break;
				}
			}
			Render::addTemplate('syslog', array(
				'machineuuid' => $client['machineuuid'],
				'list' => $log,
			));
		}
		// Notes
		Render::addTemplate('machine-notes', $client);
	}

	private function eventToIconName($event)
	{
		switch ($event) {
		case 'session-open':
			return 'glyphicon-log-in';
		case 'session-close':
			return 'glyphicon-log-out';
		case 'partition-swap':
			return 'glyphicon-info-sign';
		case 'partition-temp':
		case 'smartctl-realloc':
			return 'glyphicon-exclamation-sign';
		default:
			return 'glyphicon-minus';
		}
	}


	protected function doAjax()
	{
		$param = Request::any('lookup', false, 'string');
		if ($param === false) {
			die('No lookup given');
		}
		$add = '';
		if (preg_match('/^([a-f0-9]{4}):([a-f0-9]{4})$/', $param, $out)) {
			$cat = 'DEVICE';
			$host = $out[2] . '.' . $out[1];
			$add = ' (' . $param . ')';
		} elseif (preg_match('/^([a-f0-9]{4})$/', $param, $out)) {
			$cat = 'VENDOR';
			$host = $out[1];
		} elseif (preg_match('/^c\.([a-f0-9]{2})([a-f0-9]{2})$/', $param, $out)) {
			$cat = 'CLASS';
			$host = $out[2] . '.' . $out[1] . '.c';
		} else {
			die('Invalid format requested');
		}
		$cached = Page_Statistics::getPciId($cat, $param);
		if ($cached !== false && $cached['dateline'] > time()) {
			echo $cached['value'], $add;
			exit;
		}
		$res = dns_get_record($host . '.pci.id.ucw.cz', DNS_TXT);
		if (is_array($res)) {
			foreach ($res as $entry) {
				if (isset($entry['txt']) && substr($entry['txt'], 0, 2) === 'i=') {
					$string = substr($entry['txt'], 2);
					Page_Statistics::setPciId($cat, $param, $string);
					echo $string, $add;
					exit;
				}
			}
		}
		if ($cached !== false) {
			echo $cached['value'], $add;
			exit;
		}
		die('Not found');
	}

	public static function getPciId($cat, $id)
	{
		return Database::queryFirst('SELECT value, dateline FROM pciid WHERE category = :cat AND id = :id LIMIT 1',
			array('cat' => $cat, 'id' => $id));
	}

	private static function setPciId($cat, $id, $value)
	{
		Database::exec('INSERT INTO pciid (category, id, value, dateline) VALUES (:cat, :id, :value, :timeout)'
			. ' ON DUPLICATE KEY UPDATE value = VALUES(value), dateline = VALUES(dateline)',
			array(
				'cat' => $cat,
				'id' => $id,
				'value' => $value,
				'timeout' => time() + mt_rand(10, 30) * 86400,
			), true);
	}
}
