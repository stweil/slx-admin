<?php

class SubPage
{

	public static function doPreprocess()
	{

	}

	public static function doRender()
	{
		self::showMachine(Request::get('uuid', false, 'string'));
	}

	private static function fillSessionInfo(&$row)
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
				$row['session'] = $row['currentsession'];
				return;
			}
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
			if (empty($row['currentuser'])) {
				$row['username'] = $session['username'];
			}
		}
	}

	private static function showMachine($uuid)
	{
		$client = Database::queryFirst('SELECT machineuuid, locationid, macaddr, clientip, firstseen, lastseen, logintime, lastboot, state,
			mbram, live_tmpsize, live_tmpfree, live_swapsize, live_swapfree, live_memsize, live_memfree, Length(position) AS hasroomplan,
			kvmstate, cpumodel, id44mb, data, hostname, currentuser, currentsession, notes FROM machine WHERE machineuuid = :uuid',
			array('uuid' => $uuid));
		if ($client === false) {
			Message::addError('unknown-machine', $uuid);
			return;
		}
		if (Module::isAvailable('locations') && !Location::isLeaf($client['locationid'])) {
			$client['hasroomplan'] = false;
		}
		User::assertPermission('machine.view-details', (int)$client['locationid']);
		// Hack: Get raw collected data
		if (Request::get('raw', false)) {
			Header('Content-Type: text/plain; charset=utf-8');
			die($client['data']);
		}
		// Runmode
		if (Module::isAvailable('runmode')) {
			$data = RunMode::getRunMode($uuid, RunMode::DATA_STRINGS);
			if ($data !== false) {
				$client += $data;
			}
		}
		// Rebootcontrol
		if (Module::get('rebootcontrol') !== false) {
			$client['canReboot'] = (User::hasPermission('.rebootcontrol.action.reboot', (int)$client['locationid']));
			$client['canShutdown'] = (User::hasPermission('.rebootcontrol.action.shutdown', (int)$client['locationid']));
			$client['rebootcontrol'] = $client['canReboot'] || $client['canShutdown'];
		}
		// Baseconfig
		if (Module::get('baseconfig') !== false
			&& User::hasPermission('.baseconfig.view', (int)$client['locationid'])) {
			$cvs = Database::queryFirst('SELECT Count(*) AS cnt FROM setting_machine WHERE machineuuid = :uuid', ['uuid' => $uuid]);
			$client['overriddenVars'] = is_array($cvs) ? $cvs['cnt'] : 0;
			$client['hasBaseconfig'] = true;
		}
		if (!isset($client['isclient'])) {
			$client['isclient'] = true;
		}
		// Mangle fields
		$NOW = time();
		if (!$client['isclient']) {
			if ($client['state'] === 'IDLE') {
				$client['state'] = 'OCCUPIED';
			}
		} else {
			if ($client['state'] === 'OCCUPIED') {
				self::fillSessionInfo($client);
			}
		}
		$client['state_' . $client['state']] = true;
		$client['firstseen_s'] = date('d.m.Y H:i', $client['firstseen']);
		$client['lastseen_s'] = date('d.m.Y H:i', $client['lastseen']);
		$client['logintime_s'] = date('d.m.Y H:i', $client['logintime']);
		if ($client['lastboot'] == 0) {
			$client['lastboot_s'] = '-';
		} else {
			$uptime = $NOW - $client['lastboot'];
			$client['lastboot_s'] = date('d.m.Y H:i', $client['lastboot']);
			if ($client['state'] === 'IDLE' || $client['state'] === 'OCCUPIED') {
				$client['lastboot_s'] .= ' (Up ' . floor($uptime / 86400) . 'd ' . gmdate('H:i', $uptime) . ')';
			}
		}
		$client['gbram'] = round(ceil($client['mbram'] / 512) / 2, 1);
		$client['gbtmp'] = round($client['id44mb'] / 1024);
		foreach (['tmp', 'swap', 'mem'] as $item) {
			if ($client['live_' . $item . 'size'] == 0)
				continue;
			$client['live_' . $item . 'percent'] = round(($client['live_' . $item . 'free'] / $client['live_' . $item . 'size']) * 100, 2);
			$client['live_' . $item . 'free_s'] = Util::readableFileSize($client['live_' . $item . 'free'], -1, 2);
		}
		$client['ramclass'] = StatisticsStyling::ramColorClass($client['mbram']);
		$client['kvmclass'] = StatisticsStyling::kvmColorClass($client['kvmstate']);
		$client['hddclass'] = StatisticsStyling::hddColorClass($client['gbtmp']);
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
					// This currently requires that the partition table section comes first...
					Parser::parseSmartctl($hdds['hdds'], $section[2]);
				}
			}
		}
		unset($client['data']);
		// BIOS update check
		if (!empty($client['biosrevision'])) {
			$mainboard = $client['mobomanufacturer'] . '##' . $client['mobomodel'];
			$system = $client['pcmanufacturer'] . '##' . $client['pcmodel'];
			$ret = self::checkBios($mainboard, $system, $client['biosdate'], $client['biosrevision']);
			if ($ret === false) { // Not loaded, use AJAX
				$params = [
					'mainboard' => $mainboard,
					'system' => $system,
					'date' => $client['biosdate'],
					'revision' => $client['biosrevision'],
				];
				$client['biosurl'] = '?do=statistics&action=bios&' . http_build_query($params);
			} elseif (!isset($ret['status']) || $ret['status'] !== 0) {
				$client['bioshtml'] = Render::parse('machine-bios-update', $ret);
			}
		}
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
		Permission::addGlobalTags($client['perms'], null, ['hardware.projectors.edit', 'hardware.projectors.view']);
		// Throw output at user
		Render::addTemplate('machine-main', $client);
		// Sessions
		$NOW = time();
		$cutoff = $NOW - 86400 * 7;
		//if ($cutoff < $client['firstseen']) $cutoff = $client['firstseen'];
		$scale = 100 / ($NOW - $cutoff);
		$res = Database::simpleQuery('SELECT dateline, typeid, data FROM statistic'
			. " WHERE dateline > :cutoff AND typeid IN (:sessionLength, :offlineLength) AND machineuuid = :uuid ORDER BY dateline ASC", array(
			'cutoff' => $cutoff - 86400 * 14,
			'uuid' => $uuid,
			'sessionLength' => Statistics::SESSION_LENGTH,
			'offlineLength' => Statistics::OFFLINE_LENGTH,
		));
		$spans['rows'] = array();
		$spans['graph'] = '';
		$last = false;
		$first = true;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!$client['isclient'] && $row['typeid'] === Statistics::SESSION_LENGTH)
				continue; // Don't differentiate between session and idle for non-clients
			if ($first && $row['dateline'] > $cutoff && $client['lastboot'] > $cutoff) {
				// Special case: offline before
				$spans['graph'] .= '<div style="background:#444;left:0;width:' . round((min($row['dateline'], $client['lastboot']) - $cutoff) * $scale, 2) . '%">&nbsp;</div>';
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
			$row['from'] = Util::prettyTime($row['dateline']);
			$row['duration'] = floor($row['data'] / 86400) . 'd ' . gmdate('H:i', $row['data']);
			if ($row['typeid'] === Statistics::OFFLINE_LENGTH) {
				$row['glyph'] = 'off';
				$color = '#444';
			} elseif ($row['typeid'] === Statistics::SUSPEND_LENGTH) {
				$row['glyph'] = 'pause';
				$color = '#686';
			} else {
				$row['glyph'] = 'user';
				$color = '#e77';
			}
			$spans['graph'] .= '<div style="background:' . $color . ';left:' . round(($row['dateline'] - $cutoff) * $scale, 2) . '%;width:' . round(($row['data']) * $scale, 2) . '%">&nbsp;</div>';
			if ($client['isclient']) {
				$spans['rows'][] = $row;
			}
			$last = $row;
		}
		if ($first && $client['lastboot'] > $cutoff) {
			// Special case: offline before
			$spans['graph'] .= '<div style="background:#444;left:0;width:' . round(($client['lastboot'] - $cutoff) * $scale, 2) . '%">&nbsp;</div>';
		} elseif ($first) {
			// Not seen in last two weeks
			$spans['graph'] .= '<div style="background:#444;left:0;width:100%">&nbsp;</div>';
		}
		if ($client['state'] === 'OCCUPIED') {
			$spans['graph'] .= '<div style="background:#e99;left:' . round(($client['logintime'] - $cutoff) * $scale, 2) . '%;width:' . round(($NOW - $client['logintime'] + 900) * $scale, 2) . '%">&nbsp;</div>';
			$spans['rows'][] = [
				'from' => Util::prettyTime($client['logintime']),
				'duration' => '-',
				'glyph' => 'user',
			];
			$row['duration'] = floor($row['data'] / 86400) . 'd ' . gmdate('H:i', $row['data']);
		} elseif ($client['state'] === 'OFFLINE') {
			$spans['graph'] .= '<div style="background:#444;left:' . round(($client['lastseen'] - $cutoff) * $scale, 2) . '%;width:' . round(($NOW - $client['lastseen'] + 900) * $scale, 2) . '%">&nbsp;</div>';
			$spans['rows'][] = [
				'from' => Util::prettyTime($client['lastseen']),
				'duration' => '-',
				'glyph' => 'off',
			];
		} elseif ($client['state'] === 'STANDBY') {
			$spans['graph'] .= '<div style="background:#686;left:' . round(($client['lastseen'] - $cutoff) * $scale, 2) . '%;width:' . round(($NOW - $client['lastseen'] + 900) * $scale, 2) . '%">&nbsp;</div>';
			$spans['rows'][] = [
				'from' => Util::prettyTime($client['lastseen']),
				'duration' => '-',
				'glyph' => 'pause',
			];
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
		$spans['isclient'] = $client['isclient'];
		Render::addTemplate('machine-usage', $spans);
		// Any hdds?
		if (!empty($hdds['hdds'])) {
			Render::addTemplate('machine-hdds', $hdds);
		}
		// Client log
		if (Module::get('syslog') !== false) {
			$lres = Database::simpleQuery('SELECT logid, dateline, logtypeid, clientip, description, extra FROM clientlog'
				. ' WHERE machineuuid = :uuid ORDER BY logid DESC LIMIT 25', array('uuid' => $client['machineuuid']));
			$count = 0;
			$log = array();
			while ($row = $lres->fetch(PDO::FETCH_ASSOC)) {
				if (substr($row['description'], -5) === 'on :0' && strpos($row['description'], 'root logged') === false) {
					continue;
				}
				$row['date'] = Util::prettyTime($row['dateline']);
				$row['icon'] = self::eventToIconName($row['logtypeid']);
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
		if (User::hasPermission('machine.note.*', (int)$client['locationid'])) {
			Permission::addGlobalTags($client['perms'], (int)$client['locationid'], ['machine.note.edit']);
			Render::addTemplate('machine-notes', $client);
		}
	}

	private static function eventToIconName($event)
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

	const BIOS_CACHE = '/tmp/bwlp-bios.json';

	public static function ajaxCheckBios()
	{
		$mainboard = Request::any('mainboard', false, 'string');
		$system = Request::any('system', false, 'string');
		$date = Request::any('date', false, 'string');
		$revision = Request::any('revision', false, 'string');
		$reply = self::checkBios($mainboard, $system, $date, $revision);
		if ($reply === false) {
			$data = Download::asString(CONFIG_BIOS_URL, 3, $err);
			if ($err < 200 || $err >= 300) {
				$reply = ['error' => 'HTTP: ' . $err];
			} else {
				file_put_contents(self::BIOS_CACHE, $data);
				$data = json_decode($data, true);
				$reply = self::checkBios($mainboard, $system, $date, $revision, $data);
			}
		}
		if ($reply === false) {
			$reply = ['error' => 'Internal Error'];
		}
		if (isset($reply['status']) && $reply['status'] === 0)
			exit; // Show nothing, 0 means OK
		die(Render::parse('machine-bios-update', $reply));
	}

	private static function checkBios($mainboard, $system, $date, $revision, $json = null)
	{
		if ($json === null) {
			if (!file_exists(self::BIOS_CACHE) || filemtime(self::BIOS_CACHE) + 3600 < time())
				return false;
			$json = json_decode(file_get_contents(self::BIOS_CACHE), true);
		}
		if (!is_array($json) || !isset($json['system']))
			return ['error' => 'Malformed JSON, no system key'];
		if (isset($json['system'][$system]) && isset($json['system'][$system]['fixes']) && isset($json['system'][$system]['match'])) {
			$match =& $json['system'][$system];
		} elseif (isset($json['mainboard'][$mainboard]) && isset($json['mainboard'][$mainboard]['fixes']) && isset($json['mainboard'][$mainboard]['match'])) {
			$match =& $json['mainboard'][$mainboard];
		} else {
			return ['status' => 0];
		}
		$key = $match['match'];
		if ($key === 'revision') {
			$cmp = function ($item) { $s = explode('.', $item); return $s[0] * 0x10000 + $s[1]; };
			$reference = $cmp($revision);
		} elseif ($key === 'date') {
			$cmp = function ($item) { $s = explode('.', $item); return $s[2] * 10000 + $s[1] * 100 + $s[0]; };
			$reference = $cmp($date);
		} else {
			return ['error' => 'Invalid comparison key: ' . $key];
		}
		$retval = ['fixes' => []];
		$level = 0;
		foreach ($match['fixes'] as $fix) {
			if ($cmp($fix[$key]) > $reference) {
				class_exists('Dictionary'); // Trigger setup of lang stuff
				$lang = isset($fix['text'][LANG]) ? LANG : 'en';
				$fix['text'] = $fix['text'][$lang];
				$retval['fixes'][] = $fix;
				$level = max($level, $fix['level']);
			}
		}
		$retval['url'] = $match['url'];
		$retval['status'] = $level;
		if ($level > 5) {
			$retval['class'] = 'danger';
		} elseif ($level > 3) {
			$retval['class'] = 'warning';
		} else {
			$retval['class'] = 'info';
		}
		return $retval;
	}

}