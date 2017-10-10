<?php

class Dnbd3Util {

	public static function updateServerStatus()
	{
		$dynClients = RunMode::getForMode('dnbd3', 'proxy', false, true);
		$satServerIp = Property::getServerIp();
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
			if (!is_null($row['machineuuid']) || $row['clientip'] === $satServerIp) {
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
		// Same for this server - we use the special fixedip '<self>' for it and need to prevent we don't have the
		// IP address of the server itself in the list.
		Database::exec('DELETE FROM dnbd3_server WHERE fixedip = :serverip', array('serverip' => $satServerIp));
		Database::exec("INSERT IGNORE INTO dnbd3_server (fixedip) VALUES ('<self>')");
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

	/**
	 * A client is booting that has runmode dnbd3 proxy - set config vars accordingly.
	 *
	 * @param string $machineUuid
	 * @param string $mode always 'proxy'
	 * @param string $modeData
	 */
	public static function runmodeConfigHook($machineUuid, $mode, $modeData)
	{
		// Get all directly assigned locations
		$res = Database::simpleQuery('SELECT locationid FROM dnbd3_server
				INNER JOIN dnbd3_server_x_location USING (serverid)
				WHERE machineuuid = :uuid',
			array('uuid' => $machineUuid));
		$assignedLocs = $res->fetchAll(PDO::FETCH_ASSOC);
		if (!empty($assignedLocs)) {
			// Get all sub-locations too
			$recursiveLocs = $assignedLocs;
			$locations = Location::getLocationsAssoc();
			foreach ($assignedLocs as $l) {
				if (isset($locations[$l])) {
					$recursiveLocs = array_merge($recursiveLocs, $locations[$l]['children']);
				}
			}
			$res = Database::simpleQuery('SELECT startaddr, endaddr FROM subnet WHERE locationid IN (:locs)',
				array('locs' => $recursiveLocs));
			// Got subnets, build whitelist
			$opt = '';
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$opt .= ' ' . self::range2Cidr($row['startaddr'], $row['endaddr']);
			}
			if (!empty($opt)) {
				ConfigHolder::add('SLX_DNBD3_WHITELIST', $opt, 1000);
			}
		}
		// Send list of other proxy servers
		$res = Database::simpleQuery('SELECT s.fixedip, m.clientip, sxl.locationid FROM dnbd3_server s
				LEFT JOIN machine m USING (machineuuid)
				LEFT JOIN dnbd3_server_x_location sxl USING (serverid)
				WHERE s.machineuuid <> :uuid OR s.machineuuid IS NULL', array('uuid' => $machineUuid));
		$public = array();
		$private = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$ip = $row['clientip'] ? $row['clientip'] : $row['fixedip'];
			if ($ip === '<self>') {
				continue;
			}
			if (is_null($row['locationid'])) {
				if (!array_key_exists($ip, $private)) {
					$public[$ip] = $ip;
				}
			} else {
				$private[$ip] = $ip;
			}
		}
		if (!empty($public)) {
			ConfigHolder::add('SLX_DNBD3_PUBLIC', implode(' ', $public));
		}
		if (!empty($private)) {
			ConfigHolder::add('SLX_DNBD3_PRIVATE', implode(' ', $private));
		}
		ConfigHolder::add('SLX_ADDONS', '', 1000);
	}

	/**
	 * Get smallest subnet in CIDR notation that covers the given range.
	 * The subnet denoted by the CIDR notation might actually be larger
	 * than the range described by $start and $end.
	 *
	 * @param int $start start address
	 * @param int $end end address
	 * @return string CIDR notation
	 */
	private static function range2Cidr($start, $end)
	{
		$bin = decbin($start ^ $end);
		if ($bin === '0')
			return $start;
		$mask = 32 - strlen($bin);
		return $start . '/' . $mask;
	}

}

class Dnbd3ProxyConfig
{

	public $a;

}