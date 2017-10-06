<?php

class Dnbd3Util {

	public static function updateServerStatus()
	{
		$dynClients = RunMode::getForMode('dnbd3', 'proxy', false, true);
		$servers = array();
		$res = Database::simpleQuery('SELECT s.serverid, s.machineuuid, s.fixedip, s.lastup, s.lastdown, m.clientip
			FROM dnbd3_server s
			LEFT JOIN machine m USING (machineuuid)');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!is_null($row['clientip'])) {
				$ip = $row['clientip'];
			} elseif (!is_null($row['fixedip'])) {
				$ip = $row['fixedip'];
			} else {
				continue; // Huh?
			}
			if (!is_null($row['machineuuid'])) {
				unset($dynClients[$row['machineuuid']]);
			}
			$server = array(
				'serverid' => $row['serverid'],
				'addr' => $ip,
			);
			$servers[] = $server;
		}
		// See if any clients are in dnbd3 proxy mode but don't have a matching row in the dnbd3_server table
		foreach ($dynClients as $client) {
			Database::exec('INSERT IGNORE INTO dnbd3_server (machineuuid) VALUES (:machineuuid)',
				array('machineuuid' => $client['machineuuid']));
			// Missing from $servers now but we'll handle them in the next run, so don't bother
		}
		// Now query them all
		$NOW = time();
		foreach ($servers as $server) {
			$data = Dnbd3Rpc::query(true, false, false, $server['addr']);
			if (!is_array($data) || !isset($data['runId'])) {
				Database::exec('UPDATE dnbd3_server SET uptime = 0, clientcount = 0 WHERE serverid = :serverid',
					array('serverid' => $server['serverid']));
				continue;
			}
			// Seems up - since we only get absolute rx/tx values from the server, we have to prevent update race conditions
			// and make sure the server was not restarted in the meantime (use runid and uptime for this)
			Database::exec('UPDATE dnbd3_server SET runid = :runid, lastseen = :now, uptime = :uptime,
				totalup = totalup + If(runid = :runid AND uptime <= :uptime, If(lastup < :up, :up - lastup, 0), If(:uptime < 1800, :up, 0)),
				totaldown = totaldown + If(runid = :runid AND uptime <= :uptime, If(lastdown < :down, :down - lastdown, 0), If(:uptime < 1800, :up, 0)),
				lastup = :up, lastdown = :down, clientcount = :clientcount
				WHERE serverid = :serverid', array(
					'runid' => $data['runId'],
					'now' => $NOW,
					'uptime' => $data['uptime'],
					'up' => $data['bytesSent'],
					'down' => $data['bytesReceived'],
					'clientcount' => $data['clientCount'],
					'serverid' => $server['serverid']
			));
		}
	}

}