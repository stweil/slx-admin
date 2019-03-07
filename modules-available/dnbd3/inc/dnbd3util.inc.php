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
			if (!is_null($row['fixedip'])) {
				$ip = $row['fixedip'];
			} elseif (!is_null($row['clientip'])) {
				$ip = $row['clientip'];
			} else {
				continue; // Huh?
			}
			if (!is_null($row['machineuuid'])) {
				unset($dynClients[$row['machineuuid']]);
				if ($row['clientip'] === $satServerIp) {
					// Lolwut, sat server is openslx client configured for proxy mode!? baleeted.
					RunMode::setRunMode($row['machineuuid'], 'dnbd3', null, null, null);
					continue;
				}
			} elseif ($ip === $satServerIp) {
				// Manually configured sat server as dnbd3 server - makes no sense
				continue;
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
		// Same for this server - we use the special fixedip '<self>' for it and need to make surecx we don't have the
		// IP address of the server itself in the list.
		Database::exec('DELETE FROM dnbd3_server WHERE fixedip = :serverip', array('serverip' => $satServerIp));
		Database::exec("INSERT IGNORE INTO dnbd3_server (fixedip) VALUES ('<self>')");
		// Delete orphaned entires with machineuuid from dnbd3_server where we don't have a runmode entry
		Database::exec('DELETE s FROM dnbd3_server s
				LEFT JOIN runmode r USING (machineuuid)
				WHERE s.machineuuid IS NOT NULL AND r.module IS NULL');
		// Now query them all
		$NOW = time();
		foreach ($servers as $server) {
			$data = Dnbd3Rpc::query($server['addr'], true, false, false, true);
			if ($data === Dnbd3Rpc::QUERY_UNREACHABLE) {
				$error = 'No (HTTP) reply from ' . $server['addr'];
			} elseif ($data === Dnbd3Rpc::QUERY_NOT_200) {
				$error = 'No HTTP 200 OK from ' . $server['addr'];
			} elseif ($data === Dnbd3Rpc::QUERY_NOT_JSON) {
				$error = 'Reply to status query is not JSON';
			} elseif (!is_array($data) || !isset($data['runId'])) {
				if (is_array($data) && isset($data['errorMsg'])) {
					$error = 'DNBD3: ' . $data['errorMsg'];
				} else {
					$error = 'Reply to status query has unexpected format';
				}
			} else {
				$error = false;
			}
			if ($error !== false) {
				Database::exec('UPDATE dnbd3_server SET uptime = 0, clientcount = 0, errormsg = :errormsg WHERE serverid = :serverid',
					array('serverid' => $server['serverid'], 'errormsg' => $error));
				continue;
			}
			// Seems up - since we only get absolute rx/tx values from the server, we have to prevent update race conditions
			// and make sure the server was not restarted in the meantime (use runid and uptime for this)
			Database::exec('UPDATE dnbd3_server SET runid = :runid, lastseen = :now, uptime = :uptime,
				totalup = totalup + If(runid = :runid AND uptime <= :uptime, If(lastup < :up, :up - lastup, 0), If(:uptime < 1800, :up, 0)),
				totaldown = totaldown + If(runid = :runid AND uptime <= :uptime, If(lastdown < :down, :down - lastdown, 0), If(:uptime < 1800, :up, 0)),
				lastup = :up, lastdown = :down, clientcount = :clientcount, disktotal = :disktotal, diskfree = :diskfree, errormsg = NULL
				WHERE serverid = :serverid', array(
					'runid' => $data['runId'],
					'now' => $NOW,
					'uptime' => $data['uptime'],
					'up' => $data['bytesSent'],
					'down' => $data['bytesReceived'],
					'clientcount' => $data['clientCount'],
					'serverid' => $server['serverid'],
					'disktotal' => $data['spaceTotal'],
					'diskfree' => $data['spaceFree'],
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
		$self = Property::getServerIp();
		// Get all directly assigned locations
		$res = Database::simpleQuery('SELECT locationid FROM dnbd3_server
				INNER JOIN dnbd3_server_x_location USING (serverid)
				WHERE machineuuid = :uuid',
			array('uuid' => $machineUuid));
		$assignedLocs = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$assignedLocs[] = $row['locationid'];
		}
		$modeData = (array)json_decode($modeData, true) + self::defaultRunmodeConfig();
		if (!empty($assignedLocs) && isset($modeData['firewall']) && $modeData['firewall']) {
			// Get all sub-locations too
			$recursiveLocs = $assignedLocs;
			$locations = Location::getLocationsAssoc();
			foreach ($assignedLocs as $l) {
				if (isset($locations[$l])) {
					$recursiveLocs = array_merge($recursiveLocs, $locations[$l]['children']);
				}
			}
			$res = Database::simpleQuery('SELECT startaddr, endaddr FROM subnet WHERE locationid IN (:locs)',
				array('locs' => array_values($recursiveLocs)));
			// Coalesce overlapping ranges
			$floatIp = ip2long($self); // Float for 32bit php :/
			if (PHP_INT_SIZE === 4) {
				$floatIp = (float)sprintf('%u', $floatIp); // Float for 32bit php :/
			}
			$ranges = [['startaddr' => $floatIp, 'endaddr' => $floatIp]];
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				settype($row['startaddr'], PHP_INT_SIZE === 4 ? 'float' : 'int');
				settype($row['endaddr'], PHP_INT_SIZE === 4 ? 'float' : 'int');
				self::mergeRanges($ranges, $row);
			}
			// Got subnets, build whitelist
			$opt = '';
			foreach ($ranges as $row) {
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
		$public[$self] = $self;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$ip = $row['fixedip'] ? $row['fixedip'] : $row['clientip'];
			if ($ip === '<self>') {
				continue;
			}
			if (is_null($row['locationid'])) {
				if (!array_key_exists($ip, $private)) {
					$public[$ip] = $ip;
				}
			} else {
				unset($public[$ip]);
				$private[$ip] = $ip;
			}
		}
		if (!empty($public)) {
			shuffle($public);
			ConfigHolder::add('SLX_DNBD3_PUBLIC', implode(' ', $public));
		}
		if (!empty($private)) {
			shuffle($private);
			ConfigHolder::add('SLX_DNBD3_PRIVATE', implode(' ', $private));
		}
		if (isset($modeData['bgr']) && $modeData['bgr']) {
			// Background replication
			ConfigHolder::add('SLX_DNBD3_BGR', '1');
		}
		ConfigHolder::add('SLX_ADDONS', '', 1000);
		ConfigHolder::add('SLX_SHUTDOWN_TIMEOUT', '', 1000);
		ConfigHolder::add('SLX_SHUTDOWN_SCHEDULE', '', 1000);
		ConfigHolder::add('SLX_REBOOT_TIMEOUT', '', 1000);
		ConfigHolder::add('SLX_REBOOT_SCHEDULE', '', 1000);
		ConfigHolder::add('SLX_SYSTEM_STANDBY_TIMEOUT', '', 1000);
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
		if (PHP_INT_SIZE > 4) {
			$bin = decbin((int)$start ^ (int)$end);
		} else {
			$bin = decbin((int)(float)$start ^ (int)(float)$end);
		}
		if ($bin === '0')
			return long2ip($start);
		$mask = 32 - strlen($bin);
		return long2ip($start) . '/' . $mask;
	}

	private static function mergeRanges(&$ranges, $row)
	{
		if ($row['startaddr'] >= $row['endaddr'])
			return; // Don't even bother
		foreach (array_keys($ranges) as $key) {
			if ($row['startaddr'] <= $ranges[$key]['startaddr'] && $row['endaddr'] >= $ranges[$key]['endaddr']) {
				// Fully dominated
				unset($ranges[$key]);
				continue; // Might partially overlap with additional ranges, keep going
			}
			if ($ranges[$key]['startaddr'] <= $row['startaddr'] && $ranges[$key]['endaddr'] >= $row['startaddr']) {
				// $row['startaddr'] lies within existing range
				if ($ranges[$key]['startaddr'] <= $row['endaddr'] && $ranges[$key]['endaddr'] >= $row['endaddr'])
					return; // Fully in existing range, do nothing
				// $row['endaddr'] seems to extend range we're checking against but $row['startaddr'] lies within this range, update and keep going
				$row['startaddr'] = $ranges[$key]['startaddr'];
				unset($ranges[$key]);
				continue;
			}
			// Last possibility: $row['startaddr'] is before range, $row['endaddr'] within range
			if ($ranges[$key]['startaddr'] <= $row['endaddr'] && $ranges[$key]['endaddr'] >= $row['endaddr']) {
				// $row['startaddr'] must lie before range start, otherwise we'd have hit the case above
				$row['endaddr'] = $ranges[$key]['endaddr'];
				unset($ranges[$key]);
				continue;
			}
		}
		$ranges[] = $row;
	}

	public static function defaultRunmodeConfig()
	{
		return array(
			'bgr' => true,
			'firewall' => false
		);
	}

	public static function matchAddress($server)
	{
		if (!preg_match('/^(?:\[(?<v6a>[a-f0-9:]+)\]|(?<v6b>[a-f0-9:]+)|(?<v4>[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+))(?<port>:\d+)?$/i',
				$server, $out)) {
			return false;
		}
		foreach (['v6a', 'v6b'] as $k) {
			if (isset($out[$k])) {
				$out['v6'] = $out[$k];
				unset($out[$k]);
			}
		}
		return $out;
	}

}
