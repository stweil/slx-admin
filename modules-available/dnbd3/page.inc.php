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
		if (Request::post('re')) {
			Dnbd3Util::updateServerStatus();
			Util::redirect('?do=dnbd3');
		}
	}

	protected function doRender()
	{
		$show = Request::get('show', false, 'string');
		if ($show === 'clients') {
			$this->showClientList();
		} elseif ($show === false) {
			$this->showServerList();
		} else {
			Util::redirect('?do=dnbd3');
		}
	}

	private function showServerList()
	{
		$dynClients = RunMode::getForMode(Page::getModule(), 'proxy', true, true);
		$res = Database::simpleQuery('SELECT serverid, machineuuid, fixedip, lastseen, uptime, totalup, totaldown, clientcount FROM dnbd3_server');
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
			$servers[] = $server;
			$sort[] = $server['fixedip'] . '.' . $server['machineuuid'];
		}
		foreach ($dynClients as $server) {
			$servers[] = $server;
			$sort[] = 'A' . $server['machineuuid'];
		}
		array_multisort($sort, SORT_ASC, $servers);
		Render::addTemplate('page-serverlist', array('list' => $servers));
	}

	private function showClientList()
	{
		$serverId = Request::get('server', false, 'int');
		if ($serverId === false) {
			// TODO: Missing param
		}
		$server = Database::queryFirst('SELECT s.machineuuid, s.fixedip, m.clientip, m.hostname
			FROM dnbd3_server s
			LEFT JOIN machine m USING (machineuuid)
			WHERE s.serverid = :serverId', compact('serverId'));
		if ($server === false) {
			// TODO: Not found
		}
		if (!is_null($server['clientip'])) {
			$ip = $server['clientip'];
		} elseif (!is_null($server['fixedip'])) {
			$ip = $server['fixedip'];
		} else {
			$ip = '127.0.0.1';
		}
		$data = Dnbd3Rpc::query(false, true, false, $ip);
		if ($data === false || !isset($data['clients'])) {
			Message::addError('server-unreachable');
		} else {
			$sort = array();
			foreach ($data['clients'] as &$c) {
				$c['bytesSent_s'] = Util::readableFileSize($c['bytesSent']);
				$sort[] = $c['bytesSent'];
			}
			array_multisort($sort, SORT_DESC, $data['clients']);
			Render::addTemplate('page-clientlist', $data);
		}
	}

}
