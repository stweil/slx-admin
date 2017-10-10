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
		} elseif ($action === 'savelocations') {
			$this->saveServerLocations();
		}
		if (Request::isPost()) {
			Util::redirect('?do=dnbd3');
		}
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
		$res = Database::simpleQuery('SELECT s.serverid, s.machineuuid, s.fixedip, s.lastseen,
			s.uptime, s.totalup, s.totaldown, s.clientcount, Count(sxl.locationid) AS locations
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
				$server['uptime'] += ($NOW - $server['lastseen']);
			}
			$server['lastseen_s'] = $server['lastseen'] ? date('d.m.Y H:i', $server['lastseen']) : '-';
			$server['uptime_s'] = $server['uptime'] ? floor($server['uptime'] / 86400) . 'd ' . gmdate('H:i', $server['uptime']) : '-';
			$server['totalup_s'] = Util::readableFileSize($server['totalup']);
			$server['totaldown_s'] = Util::readableFileSize($server['totaldown']);
			$server['self'] = ($server['fixedip'] === '<self>');
			$servers[] = $server;
			if ($server['self']) {
				$sort[] = '---';
			} else {
				$sort[] = $server['fixedip'] . '.' . $server['machineuuid'];
			}
		}
		foreach ($dynClients as $server) {
			$servers[] = $server;
			$sort[] = '-' . $server['machineuuid'];
		}
		array_multisort($sort, SORT_ASC, $servers);
		Render::addTemplate('page-serverlist', array('list' => $servers));
	}

	private function showClientList()
	{
		$server = $this->getServerById();
		Render::addTemplate('page-header-servername', $server);
		$data = Dnbd3Rpc::query(false, true, false, $server['ip']);
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
			// TODO: Missing param
		}
		$server = Database::queryFirst('SELECT s.serverid, s.machineuuid, s.fixedip, m.clientip, m.hostname
			FROM dnbd3_server s
			LEFT JOIN machine m USING (machineuuid)
			WHERE s.serverid = :serverId', compact('serverId'));
		if ($server === false) {
			// TODO: Not found
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

}
