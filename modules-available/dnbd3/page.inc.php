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
		$bgr = Request::post('bgr', false, 'bool');
		$firewall = Request::post('firewall', false, 'bool');
		RunMode::setRunMode($server['machineuuid'], 'dnbd3', 'proxy',
			json_encode(compact('bgr', 'firewall')));
	}

	private function toggleUsage()
	{
		$enabled = Request::post('enabled', false, 'bool');
		Dnbd3::setEnabled($enabled);
	}

	private function saveServerLocations()
	{
		$server = $this->getServerById();
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
		if ($show === 'clients') {
			$this->showClientList();
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
		$dynClients = RunMode::getForMode(Page::getModule(), 'proxy', true, true);
		$res = Database::simpleQuery('SELECT s.serverid, s.machineuuid, s.fixedip, s.lastseen AS dnbd3lastseen,
			s.uptime, s.totalup, s.totaldown, s.clientcount, s.disktotal, s.diskfree, Count(sxl.locationid) AS locations
			FROM dnbd3_server s
			LEFT JOIN dnbd3_server_x_location sxl USING (serverid)
			GROUP BY s.serverid');
		$servers = array();
		$sort = array();
		$NOW = time();
		while ($server = $res->fetch(PDO::FETCH_ASSOC)) {
			if (isset($dynClients[$server['machineuuid']])) {
				$server += $dynClients[$server['machineuuid']];
				unset($dynClients[$server['machineuuid']]);
			}
			if ($server['uptime'] != 0) {
				$server['uptime'] += ($NOW - $server['dnbd3lastseen']);
			}
			$server['dnbd3lastseen_s'] = $server['dnbd3lastseen'] ? date('d.m.Y H:i', $server['dnbd3lastseen']) : '-';
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
			if ($server['self']) {
				$sort[] = '---';
			} else {
				$sort[] = $server['fixedip'] . '.' . $server['machineuuid'];
			}
			$servers[] = $server;
		}
		foreach ($dynClients as $server) {
			$servers[] = $server;
			$sort[] = '-' . $server['machineuuid'];
			Database::exec('INSERT INTO dnbd3_server (machineuuid) VALUES (:uuid)', array('uuid' => $server['machineuuid']));
		}
		array_multisort($sort, SORT_ASC, $servers);
		Render::addTemplate('page-serverlist', array(
			'list' => $servers,
			'enabled' => Dnbd3::isEnabled(),
			'checked_s' => Dnbd3::isEnabled() ? 'checked' : '',
		));
	}

	private function showClientList()
	{
		$server = $this->getServerById();
		Render::addTemplate('page-header-servername', $server);
		$data = Dnbd3Rpc::query($server['ip'], 5003,false, true, false, false);
		if ($data === false || !isset($data['clients'])) {
			Message::addError('server-unreachable');
			return;
		}
		$ips = array();
		$sort = array();
		foreach ($data['clients'] as &$c) {
			$c['bytesSent_s'] = Util::readableFileSize($c['bytesSent']);
			$sort[] = $c['bytesSent'];
			$ips[] = preg_replace('/:\d+$/', '', $c['address']);
		}
		array_multisort($sort, SORT_DESC, $data['clients']);
		Render::openTag('div', ['class' => 'row']);
		// Count locations
		$res = Database::simpleQuery('SELECT locationid, Count(*) AS cnt FROM machine WHERE clientip IN (:ips) GROUP BY locationid', compact('ips'));
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
			$locCount = array_filter($locCount, function ($v) { return isset($v['keep']); });
			Render::addTemplate('page-client-loclist', array('list' => array_values($locCount)));
		}
		Render::addTemplate('page-clientlist', $data);
		Render::closeTag('div');
	}

	private function showServerLocationEdit()
	{
		$server = $this->getServerById();
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
			Message::addError('main.parameter-missing', 'server');
			Util::redirect('?do=dnbd3');
		}
		$server = Database::queryFirst('SELECT s.serverid, s.machineuuid, s.fixedip, m.clientip, m.hostname
			FROM dnbd3_server s
			LEFT JOIN machine m USING (machineuuid)
			WHERE s.serverid = :serverId', compact('serverId'));
		if ($server === false) {
			Message::addError('server-non-existent', 'server');
			Util::redirect('?do=dnbd3');
		}
		if (!is_null($server['clientip'])) {
			$server['ip'] = $server['clientip'];
		} elseif (!is_null($server['fixedip'])) {
			$server['ip'] = $server['fixedip'];
		} else {
			$server['ip'] = '127.0.0.1';
		}
		return $server;
	}

	/*
	 * AJAX
	 */

	protected function doAjax()
	{
		$action = Request::any('action', false, 'string');
		if ($action === 'servertest') {
			$this->ajaxServerTest();
		} elseif ($action === 'editserver') {
			$this->ajaxEditServer();
		} else {
			die($action . '???');
		}
	}

	private function ajaxServerTest()
	{
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
		if ($reply === false)
			die('{"error": "Could not reach server"}');
		if (!is_array($reply))
			die('{"error": "No JSON received from server"}');
		if (!isset($reply['uptime']) || !isset($reply['clientCount']))
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
		$rm = RunMode::getForMode(Page::getModule(), 'proxy', false, true);
		if (!isset($rm[$server['machineuuid']])) {
			echo 'Error: RunMode entry missing.';
			return;
		}
		$modeData = json_decode($rm[$server['machineuuid']]['modedata'], true);
		if (is_array($modeData)) {
			$server += $modeData;
		}
		echo Render::parse('fragment-server-settings', $server);
	}

}
