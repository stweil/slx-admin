<?php

if (empty($_POST['type'])) die('Missing options.');
$type = mb_strtolower($_POST['type']);

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') $ip = substr($ip, 7);

/*
 * Section 1/2
 * Power/hw/usage stats
 */

if ($type{0} === '~') {
	// UUID is mandatory
	$uuid = Request::post('uuid', '', 'string');
	if (strlen($uuid) !== 36) die("Invalid UUID.\n");
	$macaddr = Request::post('macaddr', '', 'string');
	if (!empty($macaddr) && substr($uuid, 0, 16) === '000000000000001-') {
		// Override uuid if the mac is known and unique
		$res = Database::simpleQuery('SELECT machineuuid FROM machine WHERE macaddr = :macaddr AND machineuuid <> :uuid', compact('macaddr', 'uuid'));
		$override = false;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($override !== false) {
				$override = false;
				break;
			}
			$override = $row['machineuuid'];
		}
		if ($override !== false) {
			$uuid = $override;
		}
	}
	// External mode of operation?
	$mode = Request::post('mode', false, 'string');
	$NOW = time();
	$old = Database::queryFirst('SELECT clientip, logintime, lastseen, lastboot, state, mbram, cpumodel FROM machine WHERE machineuuid = :uuid', array('uuid' => $uuid));
	if ($old !== false) {
		settype($old['logintime'], 'integer');
		settype($old['lastseen'], 'integer');
		settype($old['lastboot'], 'integer');
	}
	// Handle event type
	if ($mode === false && $type === '~poweron') {
		// Poweron & hw stats
		$uptime = Request::post('uptime', 0, 'integer');
		if (strlen($macaddr) > 17) die("Invalid MAC.\n");
		if ($uptime < 0 || $uptime > 4000000) die("Implausible uptime.\n");
		$realcores = Request::post('realcores', 0, 'integer');
		if ($realcores < 0 || $realcores > 512) $realcores = 0;
		$mbram = Request::post('mbram', 0, 'integer');
		if ($mbram < 0 || $mbram > 2048000) $mbram = 0;
		$kvmstate = Request::post('kvmstate', 'UNKNOWN', 'string');
		$valid = array('UNKNOWN', 'UNSUPPORTED', 'DISABLED', 'ENABLED');
		if (!in_array($kvmstate, $valid)) $kvmstate = 'UNKNOWN';
		$cpumodel = Request::post('cpumodel', '', 'string');
		$systemmodel = Request::post('systemmodel', '', 'string');
		$id44mb = Request::post('id44mb', 0, 'integer');
		if ($id44mb < 0 || $id44mb > 10240000) $id44mb = 0;
		$badsectors = Request::post('badsectors', 0, 'integer');
		if ($badsectors < 0 || $badsectors > 100000) $badsectors = 0;
		$hostname = gethostbyaddr($ip);
		if (!is_string($hostname) || $hostname === $ip) {
			$hostname = '';
		}
		$data = Request::post('data', '', 'string');
		// Prepare insert/update to machine table
		$new = array(
			'uuid'       => $uuid,
			'macaddr'    => $macaddr,
			'clientip'   => $ip,
			'lastseen'   => $NOW,
			'lastboot'   => $NOW - $uptime,
			'realcores'  => $realcores,
			'mbram'      => $mbram,
			'kvmstate'   => $kvmstate,
			'cpumodel'   => $cpumodel,
			'systemmodel'=> $systemmodel,
			'id44mb'     => $id44mb,
			'badsectors' => $badsectors,
			'data'       => $data,
			'hostname'   => $hostname,
			'state'      => 'IDLE',
		);
		// Create/update machine entry
		if ($old === false) {
			$new['firstseen'] = $NOW;
			$new['hostname']   = $hostname;
			$res = Database::exec('INSERT INTO machine '
				. '(machineuuid, macaddr, clientip, firstseen, lastseen, logintime, position, lastboot, realcores, mbram,'
				. ' kvmstate, cpumodel, systemmodel, id44mb, badsectors, data, hostname, state) VALUES '
				. "(:uuid, :macaddr, :clientip, :firstseen, :lastseen, 0, '', :lastboot, :realcores, :mbram,"
				. ' :kvmstate, :cpumodel, :systemmodel, :id44mb, :badsectors, :data, :hostname, :state)', $new, true);
			if ($res === false) {
				die("Concurrent insert, ignored. (RESULT=0)\n");
			}
		} else {
			// Update
			$moresql = ($uptime < 180 ? ' logintime = 0, currentuser = NULL, currentsession = NULL,' : '');
			if (!empty($hostname)) {
				$new['hostname']   = $hostname;
				$moresql .= ' hostname = :hostname,';
			}
			$new['oldstate'] = $old['state'];
			$new['oldlastseen'] = $old['lastseen'];
			$res = Database::exec('UPDATE machine SET '
				. ' macaddr = :macaddr,'
				. ' clientip = :clientip,'
				. ' lastseen = :lastseen,'
				. ' lastboot = :lastboot,'
				. $moresql
				. ' realcores = :realcores,'
				. ' mbram = :mbram,'
				. ' kvmstate = :kvmstate,'
				. ' cpumodel = :cpumodel,'
				. ' systemmodel = :systemmodel,'
				. ' id44mb = :id44mb,'
				. ' badsectors = :badsectors,'
				. ' data = :data,'
				. ' state = :state    '
				. " WHERE machineuuid = :uuid AND state = :oldstate AND lastseen = :oldlastseen", $new);
			if ($res === 0) {
				die("Concurrent update, ignored. (RESULT=0)\n");
			}
		}
		// Maybe log old crashed session
		if ($uptime < 120) {
			// See if we have a lingering session, create statistic entry if so
			if ($old !== false && $old['logintime'] !== 0) {
				$sessionLength = $old['lastseen'] - $old['logintime'];
				if ($sessionLength > 30 && $sessionLength < 86400*2) {
					Database::exec('INSERT INTO statistic (dateline, typeid, machineuuid, clientip, username, data)'
						. " VALUES (:start, '~session-length', :uuid, :clientip, '', :length)", array(
						'start'     => $old['logintime'],
						'uuid'     => $uuid,
						'clientip'  => $ip,
						'length'    => $sessionLength
					));
				}
			}
			// Write poweroff period length to statistic table
			if ($old !== false && $old['lastseen'] !== 0) {
				$lastSeen = $old['lastseen'];
				$offtime = ($NOW - $uptime) - $lastSeen;
				if ($offtime > 300 && $offtime < 86400 * 90) {
					Database::exec('INSERT INTO statistic (dateline, typeid, machineuuid, clientip, username, data)'
						. " VALUES (:shutdown, '~offline-length', :uuid, :clientip, '', :length)", array(
						'shutdown' => $lastSeen,
						'uuid'     => $uuid,
						'clientip' => $ip,
						'length'   => $offtime
					));
				}
			}
		}

		if (($old === false || $old['clientip'] !== $ip) && Module::isAvailable('locations')) {
			// New, or ip changed (dynamic pool?), update subnetlicationid
			Location::updateMapIpToLocation($uuid, $ip);
		}

		// Check for suspicious hardware changes
		if ($old !== false) {
			checkHardwareChange($old, $new);
		}

		// Write statistics data

	} else if ($type === '~runstate') {
		// Usage (occupied/free)
		$sessionLength = 0;
		if ($old === false) die("Unknown machine.\n");
		if ($old['clientip'] !== $ip) {
			EventLog::warning("[runstate] IP address of client $uuid seems to have changed ({$old['clientip']} -> $ip)");
			die("Address changed.\n");
		}
		$used = Request::post('used', 0, 'integer');
		if ($old['state'] === 'OFFLINE' && $NOW - $old['lastseen'] > 600) {
			$strUpdateBoottime = ' lastboot = UNIX_TIMESTAMP(), ';
		} else {
			$strUpdateBoottime = '';
		}
		// 1) Log last session length if we didn't see the machine for a while
		if ($NOW - $old['lastseen'] > 610 && $old['lastseen'] !== 0) {
			// Old session timed out - might be caused by hard reboot
			if ($old['logintime'] !== 0) {
				if ($old['lastseen'] > $old['logintime']) {
					$sessionLength = $old['lastseen'] - $old['logintime'];
				}
				$old['logintime'] = 0;
			}
		}
		// Figure out what's happening - state changes
		$params = array(
			'uuid' => $uuid,
			'oldlastseen' => $old['lastseen'],
			'oldstate' => $old['state'],
		);
		if ($used === 0 && $old['state'] !== 'IDLE') {
			// Is not in use, was in use before
			$sessionLength = $NOW - $old['logintime'];
			$res = Database::exec('UPDATE machine SET lastseen = UNIX_TIMESTAMP(),'
				. $strUpdateBoottime
				. " logintime = 0, currentuser = NULL, state = 'IDLE' "
				. " WHERE machineuuid = :uuid AND lastseen = :oldlastseen AND state = :oldstate",
				$params);
		} elseif ($used === 1 && $old['state'] !== 'OCCUPIED') {
			// Machine is in use, was free before
			if ($sessionLength !== 0 || $old['logintime'] === 0) {
				// This event is a start of a new session, rather than an update
				$params['user'] = Request::post('user', null, 'string');
				$res = Database::exec('UPDATE machine SET lastseen = UNIX_TIMESTAMP(),'
					. $strUpdateBoottime
					. " logintime = UNIX_TIMESTAMP(), currentuser = :user, currentsession = NULL, state = 'OCCUPIED' "
					. " WHERE machineuuid = :uuid AND lastseen = :oldlastseen AND state = :oldstate", $params);
			} else {
				$res = 0;
			}
		} else {
			// Nothing changed, simple lastseen update
			$res = Database::exec('UPDATE machine SET '
				. $strUpdateBoottime
				. ' lastseen = UNIX_TIMESTAMP() WHERE machineuuid = :uuid AND lastseen = :oldlastseen AND state = :oldstate', $params);
		}
		// Did we update, or was there a concurrent update?
		if ($res === 0) {
			die("Concurrent update, ignored. (RESULT=0)\n");
		}
		// 9) Log last session length if applicable
		if ($mode === false && $sessionLength > 0 && $sessionLength < 86400*2 && $old['logintime'] !== 0) {
			Database::exec('INSERT INTO statistic (dateline, typeid, machineuuid, clientip, username, data)'
				. " VALUES (:start, '~session-length', :uuid, :clientip, '', :length)", array(
				'start'     =>  $old['logintime'],
				'uuid'     => $uuid,
				'clientip'  => $ip,
				'length'    => $sessionLength
			));
		}
	} elseif ($type === '~poweroff') {
		if ($old === false) die("Unknown machine.\n");
		if ($old['clientip'] !== $ip) {
			EventLog::warning("[poweroff] IP address of client $uuid seems to have changed ({$old['clientip']} -> $ip)");
			die("Address changed.\n");
		}
		if ($mode === false && $old['logintime'] !== 0) {
			$sessionLength = $old['lastseen'] - $old['logintime'];
			if ($sessionLength > 0 && $sessionLength < 86400*2) {
				Database::exec('INSERT INTO statistic (dateline, typeid, machineuuid, clientip, username, data)'
					. " VALUES (:start, '~session-length', :uuid, :clientip, '', :length)", array(
					'start'     => $old['logintime'],
					'uuid'     => $uuid,
					'clientip'  => $ip,
					'length'    => $sessionLength
				));
			}
		}
		Database::exec("UPDATE machine SET logintime = 0, lastseen = UNIX_TIMESTAMP(), state = 'OFFLINE'
			WHERE machineuuid = :uuid AND state = :oldstate AND lastseen = :oldlastseen",
			array('uuid' => $uuid, 'oldlastseen' => $old['lastseen'], 'oldstate' => $old['state']));
	} elseif ($mode === false && $type === '~screens') {
		if ($old === false) die("Unknown machine.\n");
		$screens = Request::post('screen', false, 'array');
		if (is_array($screens)) {
			// `devicetype`, `devicename`, `subid`, `machineuuid`
			// Make sure all screens are in the general hardware table
			$hwids = array();
			foreach ($screens as $port => $screen) {
				if (!array_key_exists('name', $screen))
					continue;
				if (array_key_exists($screen['name'], $hwids)) {
					$hwid = $hwids[$screen['name']];
				} else {
					$hwid = (int)Database::insertIgnore('statistic_hw', 'hwid',
						array('hwtype' => DeviceType::SCREEN, 'hwname' => $screen['name']));
					$hwids[$screen['name']] = $hwid;
				}
				// Now add new entries
				$machinehwid = Database::insertIgnore('machine_x_hw', 'machinehwid', array(
					'hwid' => $hwid,
					'machineuuid' => $uuid,
					'devpath' => $port,
				), array('disconnecttime' => 0));
				$validProps = array();
				if (count($screen) > 1) {
					// Screen has additional properties (resolution, size, etc.)
					unset($screen['name']);
					foreach ($screen as $key => $value) {
						if (!preg_match('/^[a-zA-Z0-9][\x21-\x7e]{0,15}$/', $key)) {
							echo "No matsch '$key'\n";
							continue; // Ignore evil key names
						}
						$validProps[] = $key;
						Database::exec("INSERT INTO machine_x_hw_prop (machinehwid, prop, value)"
							. " VALUES (:id, :key, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)", array(
							'id' => $machinehwid,
							'key' => $key,
							'value' => $value,
						));
					}
				}
				// Purge properties that might have existed in the past
				if (empty($validProps)) {
					Database::exec("DELETE FROM machine_x_hw_prop WHERE machinehwid = :machinehwid AND prop NOT LIKE '@%'",
						array('machinehwid' => $machinehwid));
				} else {
					$qs = '?' . str_repeat(',?', count($validProps) - 1);
					array_unshift($validProps, $machinehwid);
					Database::exec("DELETE FROM machine_x_hw_prop"
						. " WHERE machinehwid = ? AND prop NOT LIKE '@%' AND prop NOT IN ($qs)",
						$validProps);
				}
			}
			// Remove/disable stale entries
			if (empty($hwids)) {
				// No screens connected at all, purge all screen entries for this machine
				Database::exec("UPDATE machine_x_hw x, statistic_hw h"
					. " SET x.disconnecttime = UNIX_TIMESTAMP()"
					. " WHERE x.machineuuid = :uuid AND x.hwid = h.hwid AND h.hwtype = :type AND x.disconnecttime = 0",
					array('uuid' => $uuid, 'type' => DeviceType::SCREEN));
			} else {
				// Some screens connected, make sure old entries get removed
				$params = array_values($hwids);
				array_unshift($params, $uuid);
				array_unshift($params, DeviceType::SCREEN);
				$qs = '?' . str_repeat(',?', count($hwids) - 1);
				Database::exec("UPDATE machine_x_hw x, statistic_hw h"
					. " SET x.disconnecttime = UNIX_TIMESTAMP()"
					. " WHERE h.hwid = x.hwid AND x.disconnecttime = 0 AND h.hwtype = ? AND x.machineuuid = ? AND x.hwid NOT IN ($qs)", $params);

			}
		}
	} else if ($type === '~suspend') {
		// Client entering suspend
		if ($old === false) die("Unknown machine.\n");
		if ($old['clientip'] !== $ip) {
			EventLog::warning("[suspend] IP address of client $uuid seems to have changed ({$old['clientip']} -> $ip)");
			die("Address changed.\n");
		}
		if ($NOW - $old['lastseen'] < 610 && $old['state'] !== 'OFFLINE') {
			Database::exec("UPDATE machine SET lastseen = UNIX_TIMESTAMP(), state = 'STANDBY'
				WHERE machineuuid = :uuid AND state = :oldstate AND lastseen = :oldlastseen",
				array('uuid' => $uuid, 'oldlastseen' => $old['lastseen'], 'oldstate' => $old['state']));
		} else {
			EventLog::info("[suspend] Client $uuid reported switch to standby when it wasn't powered on first. Was: " . $old['state']);
		}
	} else if ($type === '~resume') {
		// Waking up from suspend
		if ($old === false) die("Unknown machine.\n");
		if ($old['clientip'] !== $ip) {
			EventLog::info("[resume] IP address of client $uuid seems to have changed ({$old['clientip']} -> $ip), allowed on resume.");
		}
		if ($old['state'] === 'STANDBY') {
			$res = Database::exec("UPDATE machine SET state = 'IDLE', clientip = :ip, lastseen = UNIX_TIMESTAMP()
				WHERE machineuuid = :uuid AND state = :oldstate AND lastseen = :oldlastseen",
				array('uuid' => $uuid, 'ip' => $ip, 'oldlastseen' => $old['lastseen'], 'oldstate' => $old['state']));
			// Write standby period length to statistic table
			if ($mode === false && $res > 0 && $old['lastseen'] !== 0) {
				$lastSeen = $old['lastseen'];
				$duration = $NOW - $lastSeen;
				if ($duration > 500 && $duration < 86400 * 14) {
					Database::exec('INSERT INTO statistic (dateline, typeid, machineuuid, clientip, username, data)'
						. " VALUES (:suspend, '~suspend-length', :uuid, :clientip, '', :length)", array(
						'suspend' => $lastSeen,
						'uuid'     => $uuid,
						'clientip' => $ip,
						'length'   => $duration
					));
				}
			}
		} else {
			EventLog::info("[resume] Client $uuid reported wakeup from standby when it wasn't logged as being in standby. Was: " . $old['state']);
		}
	} else {
		die("INVALID ACTION '$type'\n");
	}
	die("OK. (RESULT=0)\n");
}

/*
 * Section 2/2
 * Session information
 */

function writeStatisticLog($type, $username, $data)
{
	global $ip;
	// Spam from IP
	$row = Database::queryFirst('SELECT Count(*) AS cnt FROM statistic WHERE clientip = :client AND dateline + 300 > UNIX_TIMESTAMP()', array(':client' => $ip));
	if ($row !== false && $row['cnt'] > 8) {
		return;
	}

	Database::exec('INSERT INTO statistic (dateline, typeid, clientip, username, data) VALUES (UNIX_TIMESTAMP(), :type, :client, :username, :data)', array(
		'type' => $type,
		'client' => $ip,
		'username' => $username,
		'data' => $data,
	));
}


// For backwards compat, we require the . prefix
if ($type{0} === '.') {
	if ($type === '.vmchooser-session') {
		$user = Request::post('user', 'unknown', 'string');
		$loguser = Request::post('loguser', 0, 'int') !== 0;
		$sessionName = Request::post('name', 'unknown', 'string');
		$sessionUuid = Request::post('uuid', '', 'string');
		$session = strlen($sessionUuid) === 36 ? $sessionUuid : $sessionName;
		Database::exec("UPDATE machine SET currentuser = :user, currentsession = :session WHERE clientip = :ip",
			compact('user', 'session', 'ip'));
		writeStatisticLog('.vmchooser-session-name', ($loguser ? $user : 'anonymous'), $sessionName);
	} else {
		if (!isset($_POST['description'])) die('Missing options..');
		$description = $_POST['description'];
		// and username embedded in message
		if (preg_match('#^\[([^\]]+)\]\s*(.*)$#m', $description, $out)) {
			writeStatisticLog($type, $out[1], $out[2]);
		}
	}
}

/**
 * @param array $old row from DB with client's old data
 * @param array $new new data to be written
 */
function checkHardwareChange($old, $new)
{
	if ($new['mbram'] !== 0) {
		if ($new['mbram'] + 1000 < $old['mbram']) {
			$ram1 = round($old['mbram'] / 512) / 2;
			$ram2 = round($new['mbram'] / 512) / 2;
			EventLog::warning('[poweron] Client ' . $new['uuid'] . ' (' . $new['clientip'] . "): RAM decreased from {$ram1}GB to {$ram2}GB");
		}
		if (!empty($old['cpumodel']) && !empty($new['cpumodel']) && $new['cpumodel'] !== $old['cpumodel']) {
			EventLog::warning('[poweron] Client ' . $new['uuid'] . ' (' . $new['clientip'] . "): CPU changed from '{$old['cpumodel']}' to '{$new['cpumodel']}'");
		}
	}
}

echo "OK.\n";
