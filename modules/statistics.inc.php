<?php

global $STATS_COLORS, $SIZE_ID44, $SIZE_RAM;
$STATS_COLORS = array();
for ($i = 0; $i < 10; ++$i) {
	$STATS_COLORS[] = '#55' . sprintf('%02s%02s', dechex((($i+1)*($i+1)) / .3922), dechex(abs((5-$i) * 51)));
}
//$STATS_COLORS = array('#57e', '#ee8', '#5ae', '#fb7', '#6d7', '#e77', '#3af', '#666', '#e0e', '#999');
$SIZE_ID44 = array(0, 8, 16, 24, 30, 40, 50, 60, 80, 100, 120, 150, 180, 250, 300, 350, 400, 450, 500);
$SIZE_RAM = array(1, 2, 3, 4, 6, 8, 10, 12, 16, 24, 32, 48, 64, 96, 128, 192, 256, 320, 480, 512, 768, 1024);

class Page_Statistics extends Page
{

	protected function doPreprocess()
	{
		User::load();
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
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
				'text' => $text
			));
			Message::addSuccess('notes-saved');
			Util::redirect('?do=Statistics&uuid=' . $uuid);
		}
	}

	protected function doRender()
	{
		Render::setTitle(Dictionary::translate('lang_titleClientStatistics'));
		$uuid = Request::get('uuid', false, 'string');
		if ($uuid !== false) {
			$this->showMachine($uuid);
			return;
		}
		$filter = Request::get('filter', false, 'string');
		if ($filter !== false) {
			$argument = Request::get('argument', false, 'string');
			$this->showMachineList($filter, $argument);
			return;
		}
		Render::addScriptBottom('chart.min');
		Render::openTag('div', array('class' => 'row'));
		$this->showMemory();
		$this->showId44();
		$this->showKvmState();
		$this->showLatestMachines();
		$this->showCpuModels();
		Render::closeTag('div');
	}
	
	private function capChart(&$json, $cutoff)
	{
		$total = 0;
		foreach ($json as $entry) {
			$total += $entry['value'];
		}
		$cap = ceil($total * $cutoff);
		$accounted = 0;
		$id = 0;
		foreach ($json as $entry) {
			if ($accounted < $cap || $id < 3) {
				$id++;
				$accounted += $entry['value'];
			}
		}
		$json = array_slice($json, 0, $id);
		if ($accounted / $total < 0.99) {
			$json[] = array(
				'color' => '#eee',
				'label' => 'invalid',
				'value' => ($total - $accounted)
			);
		}
	}

	private function showCpuModels()
	{
		global $STATS_COLORS;
		$res = Database::simpleQuery("SELECT cpumodel, realcores, Count(*) AS `count` FROM machine GROUP BY cpumodel ORDER BY `count` DESC, cpumodel ASC");
		$lines = array();
		$json = array();
		$id = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			settype($row['count'], 'integer');
			$row['id'] = 'cpuid' . $id;
			$row['urlcpumodel'] = urlencode($row['cpumodel']);
			$lines[] = $row;
			$json[] = array(
				'color' => $STATS_COLORS[$id % count($STATS_COLORS)],
				'label' => 'cpuid' . $id,
				'value' => $row['count']
			);
			++$id;
		}
		$this->capChart($json, 0.92);
		Render::addTemplate('statistics/cpumodels', array('rows' => $lines, 'json' => json_encode($json)));
	}

	private function showMemory()
	{
		global $STATS_COLORS, $SIZE_RAM;
		$res = Database::simpleQuery("SELECT mbram, Count(*) AS `count` FROM machine GROUP BY mbram");
		$lines = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$gb = ceil($row['mbram'] / 1024);
			for ($i = 1; $i < count($SIZE_RAM); ++$i) {
				if ($SIZE_RAM[$i] < $gb) continue;
				if ($SIZE_RAM[$i] - $gb >= $gb - $SIZE_RAM[$i-1]) --$i;
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
				'value' => $v
			);
			++$id;
		}
		$this->capChart($json, 0.92);
		$data['json'] = json_encode($json);
		Render::addTemplate('statistics/memory', $data);
	}

	private function showKvmState()
	{
		$colors = array('UNKNOWN' => '#666', 'UNSUPPORTED' => '#ea5', 'DISABLED' => '#e55', 'ENABLED' => '#6d6');
		$res = Database::simpleQuery("SELECT kvmstate, Count(*) AS `count` FROM machine GROUP BY kvmstate ORDER BY `count` DESC");
		$lines = array();
		$json = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$lines[] = $row;
			$json[] = array(
				'color' => isset($colors[$row['kvmstate']]) ? $colors[$row['kvmstate']] : '#000',
				'label' => $row['kvmstate'],
				'value' => $row['count']
			);
		}
		Render::addTemplate('statistics/kvmstate', array('rows' => $lines, 'json' => json_encode($json)));
	}

	private function showId44()
	{
		global $STATS_COLORS, $SIZE_ID44;
		$res = Database::simpleQuery("SELECT id44mb, Count(*) AS `count` FROM machine GROUP BY id44mb");
		$lines = array();
		$total = 0;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$total += $row['count'];
			$gb = ceil($row['id44mb'] / 1024);
			for ($i = 1; $i < count($SIZE_ID44); ++$i) {
				if ($SIZE_ID44[$i] < $gb) continue;
				if ($SIZE_ID44[$i] - $gb >= $gb - $SIZE_ID44[$i-1]) --$i;
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
		$cap = ceil($total * 0.95);
		$accounted = 0;
		foreach (array_reverse($lines, true) as $k => $v) {
			$data['rows'][] = array('gb' => $k, 'count' => $v, 'class' => $this->hddColorClass($k));
			if ($accounted <= $cap) {
				if ($k === 0) {
					$color = '#e55';
				} else {
					$color = $STATS_COLORS[$id++ % count($STATS_COLORS)];
				}
				$json[] = array(
					'color' => $color,
					'label' => (string)$k,
					'value' => $v
				);
			}
			$accounted += $v;
		}
		if ($accounted / $total < 0.99) {
			$json[] = array(
				'color' => '#eee',
				'label' => 'invalid',
				'value' => ($total - $accounted)
			);
		}
		$data['json'] = json_encode($json);
		Render::addTemplate('statistics/id44', $data);
	}
	
	private function showLatestMachines()
	{
		$data = array('cutoff' => ceil(time() / 3600) * 3600 - 86400 * 7);
		$res = Database::simpleQuery("SELECT machineuuid, clientip, hostname, firstseen, mbram, kvmstate, id44mb FROM machine"
			. " WHERE firstseen > :cutoff ORDER BY firstseen DESC LIMIT 32", $data);
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
				$row['style'] = 'display:none';
			}
			$rows[] = $row;
		}
		Render::addTemplate('statistics/newclients', array('rows' => $rows, 'openbutton' => $count > 5));
	}
	
	private function showMachineList($filter, $argument)
	{
		global $SIZE_RAM, $SIZE_ID44;
		$filters = array('cpumodel', 'realcores', 'kvmstate', 'clientip', 'macaddr', 'machineuuid');
		if (in_array($filter, $filters)) {
			// Simple filters mapping into db
			$where = " $filter = :argument";
			$args = array('argument' => $argument);
		} elseif ($filter === 'gbram') {
			// Memory by rounded GB
			$lower = floor($this->findBestValue($SIZE_RAM, $argument, false) * 1024 - 100);
			$upper = ceil($this->findBestValue($SIZE_RAM, $argument, true) * 1024 + 100);
			$where = " mbram BETWEEN $lower AND $upper";
			$args = array();
		} elseif ($filter === 'hddgb') {
			// HDD by rounded GB
			$lower = floor($this->findBestValue($SIZE_ID44, $argument, false) * 1024 - 100);
			$upper = ceil($this->findBestValue($SIZE_ID44, $argument, true) * 1024 + 100);
			$where = " id44mb BETWEEN $lower AND $upper";
			$args = array();
		} elseif ($filter === 'subnet') {
			$argument = preg_replace('/[^0-9\.:]/', '', $argument);
			$where = " clientip LIKE '$argument%'";
			$args = array();
		} else {
			Message::addError('invalid-filter');
			return;
		}
		$res = Database::simpleQuery("SELECT machineuuid, macaddr, clientip, firstseen, lastseen,"
			. " logintime, lastboot, realcores, mbram, kvmstate, cpumodel, id44mb, hostname, notes IS NOT NULL AS hasnotes FROM machine"
			. " WHERE $where ORDER BY lastseen DESC, clientip ASC", $args);
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['firstseen'] = date('d.m.Y H:i', $row['firstseen']);
			$row['lastseen'] = date('d.m. H:i', $row['lastseen']);
			$row['lastboot'] = date('d.m. H:i', $row['lastboot']);
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
			if (empty($row['hostname'])) $row['hostname'] = $row['clientip'];
			$rows[] = $row;
		}
		Render::addTemplate('statistics/clientlist', array('rows' => $rows, 'filter' => $filter, 'argument' => $argument));
	}
	
	private function ramColorClass($mb)
		{
		if ($mb < 1500)
			return 'danger';
		if ($mb < 2500)
			return 'warning';
		return '';
	}

	private function kvmColorClass($state)
		{
		if ($state === 'DISABLED')
			return 'danger';
		if ($state  === 'UNKNOWN' || $state === 'UNSUPPORTED')
			return 'warning';
		return '';
	}

	private function hddColorClass($gb)
		{
		if ($gb < 7)
			return 'danger';
		if ($gb < 25)
			return 'warning';
		return '';
	}

	private function findBestValue($array, $value, $up)
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
	
	private function showMachine($uuid)
		{
		$row = Database::queryFirst("SELECT machineuuid, macaddr, clientip, firstseen, lastseen, logintime, lastboot,"
			. " mbram, kvmstate, cpumodel, id44mb, data, hostname, notes FROM machine WHERE machineuuid = :uuid",
			array('uuid' => $uuid));
		// Mangle fields
		$row['firstseen'] = date('d.m.Y H:i', $row['firstseen']);
		$row['lastseen'] = date('d.m.Y H:i', $row['lastseen']);
		$row['lastboot'] = date('d.m.Y H:i', $row['lastboot']);
		$row['gbram'] = round(round($row['mbram'] / 500) / 2, 1);
		$row['gbtmp'] = round($row['id44mb'] / 1024);
		$row['ramclass'] = $this->ramColorClass($row['mbram']);
		$row['kvmclass'] = $this->kvmColorClass($row['kvmstate']);
		$row['hddclass'] = $this->hddColorClass($row['gbtmp']);
		// Parse the giant blob of data
		$hdds = array();
		if (preg_match_all('/##### ([^#]+) #+$(.*?)^#####/ims', $row['data'] . '########', $out, PREG_SET_ORDER)) {
			foreach ($out as $section) {
				if ($section[1] === 'CPU') {
					$this->parseCpu($row, $section[2]);
				}
				if ($section[1] === 'dmidecode') {
					$this->parseDmiDecode($row, $section[2]);
				}
				if ($section[1] === 'Partition tables') {
					$this->parseHdd($hdds, $section[2]);
				}
			}
		}
		// Throw output at user
		Render::addTemplate('statistics/machine-main', $row);
		// Any hdds?
		if (!empty($hdds['hdds'])) {
			Render::addScriptBottom('chart.min');
			Render::addTemplate('statistics/machine-hdds', $hdds);
		}
		Render::addTemplate('statistics/machine-notes', $row);
	}
	
	private function parseCpu(&$row, $data)
	{
		if (0 >= preg_match_all('/^(.+):\s+(\d+)$/im', $data, $out, PREG_SET_ORDER)) return;
		foreach ($out as $entry) {
			$row[str_replace(' ', '', $entry[1])] = $entry[2];
		}
	}
	
	private function parseDmiDecode(&$row, $data)
	{
		$lines = preg_split("/[\r\n]+/", $data);
		$section = false;
		$ramOk = false;
		$ramForm = $ramType = $ramSpeed = $ramClockSpeed = false;
		foreach ($lines as $line) {
			if ($line{0} !== "\t" && $line{0} !== ' ') {
				$section = $line;
				$ramOk = false;
				if (($ramForm || $ramType) && ($ramSpeed || $ramClockSpeed)) {
					if (isset($row['ramtype']) && !$ramClockSpeed) continue;
					$row['ramtype'] = $ramType . ' ' . $ramForm;
					if ($ramClockSpeed) $row['ramtype'] .= ', ' . $ramClockSpeed;
					elseif ($ramSpeed) $row['ramtype'] .= ', ' . $ramSpeed;
					$ramForm = false;
					$ramType = false;
					$ramClockSpeed = false;
				}
				continue;
			}
			if ($section === 'System Information' || $section === 'Base Board Information') {
				if (empty($row['pcmodel']) && preg_match('/^\s*Product Name: +(\S.+?) *$/i', $line, $out)) {
					$row['pcmodel'] = $out[1];
				}
				if (empty($row['manufacturer']) && preg_match('/^\s*Manufacturer: +(\S.+?) *$/i', $line, $out)) {
					$row['manufacturer'] = $out[1];
				}
			}
			else if ($section === 'Physical Memory Array') {
				if (!$ramOk && preg_match('/Use: System Memory/i', $line)) {
					$ramOk = true;
				}
				if ($ramOk && preg_match('/^\s*Number Of Devices: +(\S.+?) *$/i', $line, $out)) {
					$row['ramslotcount'] = $out[1];
				}
				if ($ramOk && preg_match('/^\s*Maximum Capacity: +(\S.+?)\s*$/i', $line, $out)) {
					$row['maxram'] = preg_replace('/([MGT])B/', '$1iB', $out[1]);
				}
			}
			else if ($section === 'Memory Device') {
				if (preg_match('/^\s*Size:\s*(.*?)\s*$/i', $line, $out)) {
					$row['extram'] = true;
					if (preg_match('/(\d+)\s*(\w)i?B/i', $out[1], $out)) {
						$out[2] = strtoupper($out[2]);
						if ($out[2] === 'K' || ($out[2] === 'M' && $out[1] < 500)) {
							$ramForm = $ramType = $ramSpeed = $ramClockSpeed = false;
							continue;
						}
						if ($out[2] === 'M' && $out[1] >= 1024) {
							$out[2] = 'G';
							$out[1] = floor(($out[1] + 100) / 1024);
						}
						$row['ramslot'][]['size'] = $out[1] . ' ' . strtoupper($out[2]) . 'iB';
					} else if (count($row['ramslot']) < 8 && (!isset($row['ramslotcount']) || $row['ramslotcount'] <= 8)) {
						$row['ramslot'][]['size'] = '_____';
					}
				}
				if (preg_match('/^\s*Form Factor:\s*(.*?)\s*$/i', $line, $out) && $out[1] !== 'Unknown') {
					$ramForm = $out[1];
				}
				if (preg_match('/^\s*Type:\s*(.*?)\s*$/i', $line, $out) && $out[1] !== 'Unknown') {
					$ramType = $out[1];
				}
				if (preg_match('/^\s*Speed:\s*(\d.*?)\s*$/i', $line, $out)) {
					$ramSpeed = $out[1];
				}
				if (preg_match('/^\s*Configured Clock Speed:\s*(\d.*?)\s*$/i', $line, $out)) {
					$ramClockSpeed = $out[1];
				}
			}
		}
		if (empty($row['ramslotcount'])) $row['ramslotcount'] = count($row['ramslot']);
	}
	
	private function parseHdd(&$row, $data)
	{
		$hdds = array();
		// Could have more than one partition - linear scan
		$lines = preg_split("/[\r\n]+/", $data);
		$dev = false;
		$i = 0;
		foreach ($lines as $line) {
			if (preg_match('/^Disk (\S+):.* (\d+) bytes/i', $line, $out)) {
				// disk total size and name
				unset($hdd);
				$unit = 0;
				$hdd = array(
					'devid' => 'devid-' . ++$i,
					'dev' => $out[1],
					'size' => round($out[2] / (1024 * 1024 * 1024)),
					'used' => 0,
					'partitions' => array(),
					'json' => array(),
				);
				$hdds[] = &$hdd;
			} elseif (preg_match('/^Units =.*= (\d+) bytes/i', $line, $out)) {
				// Unit for start and end
				$unit = $out[1] / (1024 * 1024); // Convert so that multiplying by unit yields MiB
			} else if (isset($hdd) && $unit !== 0 && preg_match(',^/dev/(\S+)\s+.*\s(\d+)\s+(\d+)\s+\d+\s+([0-9a-f]+)\s+(.*)$,i', $line, $out)) {
				// Some partition
				$type = strtolower($out[4]);
				if ($type === '5' || $type === 'f' || $type === '85') continue;
				$partsize = round(($out[3] - $out[2]) * $unit);
				$hdd['partitions'][] = array(
					'id' => $out[1],
					'name' => $out[1],
					'size' => round($partsize / 1024, $partsize < 1024 ? 1 : 0),
					'type' => ($type === '44' ? 'OpenSLX' : $out[5]),
				);
				$hdd['json'][] = array(
					'label' => $out[1],
					'value' => $partsize,
					'color' => ($type === '44' ? '#4d4' : ($type === '82' ? '#48f' : '#e55')),
				);
				$hdd['used'] += $partsize;
			}
		}
		unset($hdd);
		$i = 0;
		foreach ($hdds as &$hdd) {
			$hdd['used'] = round($hdd['used'] / 1024);
			$free = $hdd['size'] - $hdd['used'];
			if ($free > 5) {
				$hdd['partitions'][] = array(
					'id'   => 'free-id-' . $i,
					'name' => Dictionary::translate('unused'),
					'size' => $free,
					'type' => '-',
				);
				$hdd['json'][] = array(
					'label' => 'free-id-' . $i,
					'value' => $free * 1024,
					'color' => '#aaa',
				);
				++$i;
			}
			$hdd['json'] = json_encode($hdd['json']);
		}
		unset($hdd);
		$row['hdds'] = &$hdds;
	}

}
