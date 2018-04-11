<?php

class Page_Dnbd3 extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$action = Request::post('action', false, 'string');
		if ($action === 'refresh') {
			User::assertPermission('refresh');
			Dnbd3Util::updateServerStatus();
		} elseif ($action === 'delserver') {
			$this->deleteServer();
		} elseif ($action === 'addserver') {
			$this->addServer();
		} elseif ($action === 'editserver') {
			$this->editServer();
		} elseif ($action === 'savelocations') {
			$this->saveServerLocations();
		} elseif ($action === 'toggle-usage') {
			$this->toggleUsage();
		}
		if (Request::isPost()) {
			Util::redirect('?do=dnbd3');
		}
	}

	private function editServer()
	{
		$server = $this->getServerById();
		if (!isset($server['machineuuid'])) {
			Message::addError('not-automatic-server', $server['ip']);
			return;
		}
		$this->assertPermission($server);
		$bgr = Request::post('bgr', false, 'bool');
		$firewall = Request::post('firewall', false, 'bool');
		$overrideIp = false;
		$sip = Request::post('fixedip', null, 'string');
		if (empty($sip)) {
			$overrideIp = null;
		} elseif ($server['fixedip'] !== $overrideIp) {
			$ip = ip2long(trim($sip));
			if ($ip !== false) {
				$ip = long2ip($ip);
			}
			if ($ip === false) {
				Message::addError('invalid-ipv4', $sip);
				return;
			}
			$res = Database::queryFirst('SELECT serverid FROM dnbd3_server s
					LEFT JOIN machine m USING (machineuuid)
					WHERE s.fixedip = :ip OR m.clientip = :ip', compact('ip'));
			if ($res !== false) {
				Message::addError('server-already-exists', $ip);
				return;
			}
			$overrideIp = $ip;
		}
		if ($overrideIp !== false) {
			Database::exec('UPDATE dnbd3_server SET fixedip = :fixedip WHERE machineuuid = :uuid', array(
				'uuid' => $server['machineuuid'],
				'fixedip' => $overrideIp,
			));
		}
		RunMode::setRunMode($server['machineuuid'], 'dnbd3', 'proxy',
			json_encode(compact('bgr', 'firewall')), false);
	}

	private function toggleUsage()
	{
		User::assertPermission('toggle-usage');
		$enabled = Request::post('enabled', false, 'bool');
		$nfs = Request::post('with-nfs', false, 'bool');
		$task = Dnbd3::setEnabled($enabled);
		Dnbd3::setNfsFallback($nfs);
		Taskmanager::waitComplete($task, 5000);
	}

	private function saveServerLocations()
	{
		$server = $this->getServerById();
		$this->assertPermission($server);
		$locids = Request::post('location', [], 'array');
		if (empty($locids)) {
			Database::exec('DELETE FROM dnbd3_server_x_location WHERE serverid = :serverid',
				array('serverid' => $server['serverid']));
		} else {
			Database::exec('DELETE FROM dnbd3_server_x_location WHERE serverid = :serverid AND locationid NOT IN (:lids)',
				array('serverid' => $server['serverid'], 'lids' => $locids));
			foreach ($locids as $lid) {
				Database::exec('INSERT IGNORE INTO dnbd3_server_x_location (serverid, locationid) VALUES (:serverid, :lid)',
					array('serverid' => $server['serverid'], 'lid' => $lid));
			}
		}
	}

	private function addServer()
	{
		User::assertPermission('configure.external');
		$ip = Request::post('newip', false, 'string');
		if ($ip === false) {
			Message::addError('main.parameter-missing', 'ip');
			return;
		}
		$ip = ip2long(trim($ip));
		if ($ip !== false) {
			$ip = long2ip($ip);
		}
		if ($ip === false) {
			Message::addError('invalid-ipv4', $ip);
			return;
		}
		$res = Database::queryFirst('SELECT serverid FROM dnbd3_server s
					LEFT JOIN machine m USING (machineuuid)
					WHERE s.fixedip = :ip OR m.clientip = :ip', compact('ip'));
		if ($res !== false) {
			Message::addError('server-already-exists', $ip);
			return;
		}
		Database::exec('INSERT INTO dnbd3_server (fixedip) VALUES (:ip)', compact('ip'));
		Message::addSuccess('server-added', $ip);
	}

	private function deleteServer()
	{
		$server = $this->getServerById();
		$this->assertPermission($server);
		if ($server['fixedip'] === '<self>')
			return;
		if (!is_null($server['machineuuid'])) {
			RunMode::setRunMode($server['machineuuid'], 'dnbd3', null, null, null);
		}
		Database::exec('DELETE FROM dnbd3_server WHERE serverid = :serverid',
			array('serverid' => $server['serverid']));
		Message::addSuccess('server-deleted', $server['ip']);
	}

	/*
	 * RENDER
	 */

	protected function doRender()
	{
		$show = Request::get('show', false, 'string');
		if ($show === 'proxy') {
			$this->showProxyDetails();
		} elseif ($show === 'locations') {
			$this->showServerLocationEdit();
		} elseif ($show === false) {
			$this->showServerList();
		} else {
			Util::redirect('?do=dnbd3');
		}
	}

	private function showServerList()
	{
		User::assertPermission('access-page');
		$dynClients = RunMode::getForMode(Page::getModule(), 'proxy', true, true);
		$res = Database::simpleQuery('SELECT s.serverid, s.machineuuid, s.fixedip, s.lastseen AS dnbd3lastseen,
			s.uptime, s.totalup, s.totaldown, s.clientcount, s.disktotal, s.diskfree, GROUP_CONCAT(sxl.locationid) AS locations,
			s.errormsg
			FROM dnbd3_server s
			LEFT JOIN dnbd3_server_x_location sxl USING (serverid)
			GROUP BY s.serverid');
		$servers = array();
		$sort = array();
		$NOW = time();
		$externalAllowed = User::hasPermission('configure.external');
		$locsRunmode = User::getAllowedLocations('configure.proxy');
		while ($server = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!is_null($server['machineuuid'])) {
				// Auto proxy
				if (!isset($dynClients[$server['machineuuid']])) {
					// Not in runmode dnbd3!?
					if ($NOW - $server['dnbd3lastseen'] > 660) {
						// Also seems to be down - delete
						Database::exec('DELETE FROM dnbd3_server WHERE serverid = :serverid',
							array('serverid' => $server['serverid']));
						continue;
					}
					// Not in runmode but (still?) up -- show
					$server += ['locationid' => null, 'hostname' => '<invalid>'];
				}
				$server += $dynClients[$server['machineuuid']];
				unset($dynClients[$server['machineuuid']]);
			}
			if ($server['uptime'] != 0) {
				$server['uptime'] += ($NOW - $server['dnbd3lastseen']);
			}
			$server['dnbd3lastseen_s'] = $server['dnbd3lastseen'] ? Util::prettyTime($server['dnbd3lastseen']) : '-';
			$server['uptime_s'] = $server['uptime'] ? floor($server['uptime'] / 86400) . 'd ' . gmdate('H:i', $server['uptime']) : '-';
			$server['totalup_s'] = Util::readableFileSize($server['totalup']);
			$server['totaldown_s'] = Util::readableFileSize($server['totaldown']);
			if ($server['disktotal'] > 0) {
				$server['disktotal_s'] = Util::readableFileSize($server['disktotal']);
				$server['diskfree_s'] = Util::readableFileSize($server['diskfree']);
				$server['diskUsePercent'] = floor(100 - 100 * $server['diskfree'] / $server['disktotal']);
			} else {
				$server['disktotal_s'] = '?';
				$server['diskfree_s'] = '?';
				$server['diskUsePercent'] = 0;
			}
			$server['self'] = ($server['fixedip'] === '<self>');
			if (isset($server['clientip']) && !is_null($server['clientip'])) {
				if ($NOW - $server['lastseen'] > 360) {
					$server['slxDown'] = true;
				} else {
					$server['slxOk'] = true;
				}
			}
			if (is_null($server['locations'])) {
				$server['locations'] = 0;
			} else {
				$locations = explode(',', $server['locations']);
				$server['locations'] = count($locations);
			}
			// Permission to edit
			if (is_null($server['machineuuid'])) {
				if (!$externalAllowed) {
					$server['edit_disabled'] = 'disabled';
				}
			} else {
				if (!array_key_exists('locationid', $server) || !in_array($server['locationid'], $locsRunmode)) {
					$server['edit_disabled'] = 'disabled';
				}
			}
			// Array for sorting
			if ($server['self']) {
				$sort[] = '---';
			} else {
				$sort[] = $server['fixedip'] . '.' . $server['machineuuid'];
			}
			$servers[] = $server;
		}
		foreach ($dynClients as $server) {
			$server['edit_disabled'] = 'disabled';
			$servers[] = $server;
			$sort[] = '-' . $server['machineuuid'];
			Database::exec('INSERT IGNORE INTO dnbd3_server (machineuuid) VALUES (:uuid)', array('uuid' => $server['machineuuid']));
		}
		array_multisort($sort, SORT_ASC, $servers);
		$data = array(
			'list' => $servers,
			'enabled' => Dnbd3::isEnabled(),
			'enabled_checked_s' => Dnbd3::isEnabled() ? 'checked' : '',
			'nfs_checked_s' => Dnbd3::hasNfsFallback() ? 'checked' : '',
			'rebootcontrol' => Module::isAvailable('rebootcontrol', false)
		);
		Permission::addGlobalTags($data['perms'], null, ['view.details', 'refresh', 'toggle-usage', 'configure.proxy', 'configure.external']);
		Render::addTemplate('page-serverlist', $data);
	}

	private function showProxyDetails()
	{
		User::assertPermission('view.details');
		$server = $this->getServerById();
		Render::addTemplate('page-proxy-header', $server);
		$stats = Dnbd3Rpc::query($server['ip'], 5003, true, true, true, true, true, true);
		if (!is_array($stats) || !isset($stats['runId'])) {
			Message::addError('server-unreachable');
			return;
		}
		foreach (['bytesSent', 'bytesReceived', 'spaceTotal', 'spaceFree'] as $key) {
			$stats[$key . '_s'] = Util::readableFileSize($stats[$key]);
		}
		if ($stats['spaceTotal'] > 0) {
			$stats['percentFree'] = ($stats['spaceFree'] / $stats['spaceTotal']) * 100;
			$stats['percentFree'] = round($stats['percentFree'], $stats['percentFree'] < 10 ? 1 : 0);
		}
		$stats['uptime_s'] = floor($stats['uptime'] / 86400) . 'd ' . gmdate('H:i:s', $stats['uptime']);
		$stats['tab_config'] = is_string($stats['config']);
		$stats['tab_altservers'] = is_array($stats['altservers']);
		Render::addTemplate('page-proxy-stats', $stats);
		Render::openTag('div', ['class' => 'tab-content']);
		$ips = array();
		$sort = array();
		foreach ($stats['clients'] as &$c) {
			$c['bytesSent_s'] = Util::readableFileSize($c['bytesSent']);
			$sort[] = $c['bytesSent'];
			$ips[preg_replace('/:\d+$/', '', $c['address'])] = true;
		}
		$ips = array_keys($ips);
		array_multisort($sort, SORT_DESC, $stats['clients']);
		// Config
		if (is_string($stats['config'])) {
			preg_match_all('/^((?<sec>\[.*\])|(?<key>[^=]+)=(?<val>.*)|(?<other>[^\[][^=]*))$/m', $stats['config'], $out, PREG_SET_ORDER);
			$stats['config'] = [];
			foreach ($out as $line) {
				if (!empty($line['sec'])) {
					$stats['config'][] = ['class1' => 'text-primary', 'text1' => $line['sec']];
				} elseif (!empty($line['other'])) {
					$stats['config'][] = ['class1' => 'text-muted', 'text1' => $line['other']];
				} else {
					$extra = '';
					$class2 = 'slx-bold';
					if (in_array($line['key'], ['serverPenalty', 'clientPenalty'])) {
						$extra = round($line['val'] / 1000, 1) . 'ms';
					} elseif (in_array($line['key'], ['uplinkTimeout', 'clientTimeout'])) {
						$extra = round($line['val'] / 1000, 1) . 's';
					} elseif (in_array($line['key'], ['maxPayload', 'maxReplicationSize'])) {
						$extra = Util::readableFilesize($line['val']);
					} elseif ($line['val'] === 'true') {
						$class2 .= ' text-success';
					} elseif ($line['val'] === 'false') {
						$class2 .= ' text-danger';
					}
					$stats['config'][] = ['text1' => $line['key'], 'class2' => $class2, 'text2' => $line['val'] . ' ', 'extra' => $extra];
				}
			}
			Render::addTemplate('page-proxy-config', $stats);
		}
		if (is_array($stats['altservers'])) {
			foreach ($stats['altservers'] as &$as) {
				$as['rtt'] = round(array_sum($as['rtt']) / count($as['rtt']) / 1000, 2);
			}
			unset($as);
			Render::addTemplate('page-proxy-altservers', $stats);
		}
		// Count locations
		$res = Database::simpleQuery("SELECT locationid, Count(*) AS cnt FROM machine
				WHERE clientip IN (:ips) AND state IN ('IDLE', 'OCCUPIED') GROUP BY locationid", compact('ips'));
		$locCount = Location::getLocationsAssoc();
		$locCount[0] = array(
			'locationname' => '/',
			'depth' => 0,
			'recCount' => 0,
		);
		foreach ($locCount as &$loc) {
			$loc['recCount'] = 0;
		}
		$showLocs = false;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			settype($row['locationid'], 'int');
			$loc =& $locCount[$row['locationid']];
			$loc['clientCount'] = $row['cnt'];
			$loc['recCount'] += $row['cnt'];
			if ($row['locationid'] !== 0) {
				$showLocs = true;
			}
			$loc['keep'] = true;
			if (isset($loc['parents'])) {
				foreach ($loc['parents'] as $p) {
					$locCount[$p]['keep'] = true;
					$locCount[$p]['recCount'] += $row['cnt'];
				}
			}
		}
		if ($showLocs) {
			$stats['loclist'] = array_values(array_filter($locCount, function ($v) { return isset($v['keep']); }));
		}
		Render::addTemplate('page-proxy-clients', $stats);
		$sort1 = $sort2 = [];
		foreach ($stats['images'] as &$image) {
			$image['size_s'] = Util::readableFileSize($image['size']);
			$sort1[] = $image['users'];
			$sort2[] = $image['name'];
		}
		array_multisort($sort1, SORT_NUMERIC | SORT_DESC, $sort2, SORT_ASC, $stats['images']);
		Render::addTemplate('page-proxy-images', $stats);
		Render::closeTag('div');
	}

	private function showServerLocationEdit()
	{
		$server = $this->getServerById();
		$this->assertPermission($server);
		// Get selected ones
		$res = Database::simpleQuery('SELECT locationid FROM dnbd3_server_x_location WHERE serverid = :serverid',
			array('serverid' => $server['serverid']));
		$selectedLocations = array();
		while ($loc = $res->fetchColumn(0)) {
			$selectedLocations[$loc] = true;
		}
		// Build location list
		$server['locations'] = array_values(Location::getSubnetsByLocation());
		$filtered = array();
		foreach ($server['locations'] as &$loc) {
			$filtered['l'.$loc['locationid']] = array(
				'children' => $loc['children'],
				'subnets' => $loc['subnets']
			);
			if (isset($selectedLocations[$loc['locationid']])) {
				$loc['checked_s'] = 'checked';
			}
		}
		unset($loc);
		$server['jsonLocations'] = json_encode($filtered);
		Render::addTemplate('page-server-locations', $server);
	}

	private function getServerById($serverId = false)
	{
		if ($serverId === false) {
			$serverId = Request::any('server', false, 'int');
		}
		if ($serverId === false) {
			if (AJAX)
				die('Missing parameter');
			Message::addError('main.parameter-missing', 'server');
			Util::redirect('?do=dnbd3');
		}
		$server = Database::queryFirst('SELECT s.serverid, s.machineuuid, s.fixedip, m.clientip, m.hostname, m.locationid
			FROM dnbd3_server s
			LEFT JOIN machine m USING (machineuuid)
			WHERE s.serverid = :serverId', compact('serverId'));
		if ($server === false) {
			if (AJAX)
				die('Invalid server id');
			Message::addError('server-non-existent', $serverId);
			Util::redirect('?do=dnbd3');
		}
		if (!is_null($server['fixedip'])) {
			$server['ip'] = $server['fixedip'];
		} elseif (!is_null($server['clientip'])) {
			$server['ip'] = $server['clientip'];
		} else {
			$server['ip'] = '127.0.0.1';
		}
		return $server;
	}

	private function assertPermission($server)
	{
		if (isset($server['machineuuid'])) {
			User::assertPermission('configure.proxy', $server['locationid'], '?do=dnbd3');
		} else {
			User::assertPermission('configure.external', null, '?do=dnbd3');
		}
	}

	/*
	 * AJAX
	 */

	protected function doAjax()
	{
		User::load();
		if (!User::isLoggedIn())
			die('No');
		$action = Request::any('action', false, 'string');
		if ($action === 'servertest') {
			$this->ajaxServerTest();
		} elseif ($action === 'editserver') {
			$this->ajaxEditServer();
		} elseif ($action === 'reboot') {
			$this->ajaxReboot();
		} else {
			die($action . '???');
		}
	}

	private function ajaxServerTest()
	{
		User::assertPermission('configure.external');
		Header('Content-Type: application/json; charset=utf-8');
		$ip = Request::post('ip', false, 'string');
		if ($ip === false)
			die('{"error": "Missing parameter", "fatal": true}');
		$ip = ip2long(trim($ip));
		if ($ip !== false) {
			$ip = long2ip($ip);
		}
		if ($ip === false)
			die('{"error": "Supports IPv4 only", "fatal": true}');
		// Dup?
		$res = Database::queryFirst('SELECT serverid FROM dnbd3_server s
					LEFT JOIN machine m USING (machineuuid)
					WHERE s.fixedip = :ip OR m.clientip = :ip', compact('ip'));
		if ($res !== false)
			die('{"error": "Server with this IP already exists", "fatal": true}');
		// Query
		$reply = Dnbd3Rpc::query($ip, 5003,true, false, false, true);
		if ($reply === Dnbd3Rpc::QUERY_UNREACHABLE)
			die('{"error": "Could not reach server"}');
		if ($reply === Dnbd3Rpc::QUERY_NOT_200)
			die('{"error": "Server did not reply with 200 OK"}');
		if ($reply === Dnbd3Rpc::QUERY_NOT_JSON)
			die('{"error": "No JSON received from server"}');
		if (!is_array($reply) || !isset($reply['uptime']) || !isset($reply['clientCount']))
			die('{"error": "Reply does not suggest this is a dnbd3 server"}');
		echo json_encode($reply);
	}

	private function ajaxEditServer()
	{
		$server = $this->getServerById();
		if (!isset($server['machineuuid'])) {
			echo 'Not automatic server.';
			return;
		}
		$this->assertPermission($server);
		$rm = RunMode::getForMode(Page::getModule(), 'proxy', false, true);
		if (!isset($rm[$server['machineuuid']])) {
			echo 'Error: RunMode entry missing.';
			return;
		}
		$modeData = (array)json_decode($rm[$server['machineuuid']]['modedata'], true);
		$server += $modeData + Dnbd3Util::defaultRunmodeConfig();
		echo Render::parse('fragment-server-settings', $server);
	}

	private function ajaxReboot()
	{
		$server = $this->getServerById();
		if (!isset($server['machineuuid'])) {
			die('Not automatic server.');
		}
		$uuid = $server['machineuuid'];
		$task = Request::any('taskid', false, 'string');
		if ($task === false) {
			$this->assertPermission($server);
			if (!Module::isAvailable('rebootcontrol')) {
				die('No rebootcontrol');
			}
			$task = RebootControl::reboot([$uuid]);
			if ($task === false) {
				die('Taskmanager unreachable');
			}
		}
		$task = Taskmanager::waitComplete($task, 1000);
		if (is_array($task) && isset($task['data']['clientStatus'][$uuid])) {
			$status = [
				'rebootStatus' => $task['data']['clientStatus'][$uuid],
				'taskStatus' => $task['statusCode'],
				'taskId' => $task['id'],
			];
			if (!empty($task['data']['error'])) {
				$status['error'] = $task['data']['error'];
			}
		} else {
			$status = [
				'rebootStatus' => 'FAILURE',
				'taskStatus' => 'FAILURE',
				'taskId' => $task['id'],
			];
		}
		Header('Content-Type: application/json; charset=utf-8');
		die(json_encode($status));
	}

}
