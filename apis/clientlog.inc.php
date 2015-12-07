<?php

if (empty($_POST['type'])) die('Missing options.');
$type = mb_strtolower($_POST['type']);

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') $ip = substr($ip, 7);

/*
 * Power/hw/usage stats
 */

if ($type{0} === '~') {
	// UUID is mandatory
	$uuid = Request::post('uuid', '', 'string');
	if (strlen($uuid) !== 36) die("Invalid UUID.\n");
	$macaddr = Request::post('macaddr', '', 'string');
	if (!empty($macaddr) && substr($uuid, 0, 16) === '000000000000000-') {
		// Override uuid if the mac is known and unique
		$res = Database::simpleQuery('SELECT machineuuid FROM machine WHERE macaddr = :macaddr', array('macaddr' => $macaddr));
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
	$NOW = time();
	// Handle event type
	if ($type === '~poweron') {
		// Poweron & hw stats
		$uptime = Request::post('uptime', '', 'integer');
		if (strlen($macaddr) > 17) die("Invalid MAC.\n");
		if ($uptime < 0 || $uptime > 4000000) die("Implausible uptime.\n");
		$realcores = Request::post('realcores', 0, 'integer');
		if ($realcores < 0 || $realcores > 512) $realcores = 0;
		$mbram = Request::post('mbram', 0, 'integer');
		if ($mbram < 0 || $mbram > 102400) $mbram = 0;
		$kvmstate = Request::post('kvmstate', 'UNKNOWN', 'string');
		$valid = array('UNKNOWN', 'UNSUPPORTED', 'DISABLED', 'ENABLED');
		if (!in_array($kvmstate, $valid)) $kvmstate = 'UNKNOWN';
		$cpumodel = Request::post('cpumodel', '', 'string');
		$id44mb = Request::post('id44mb', 0, 'integer');
		if ($id44mb < 0 || $id44mb > 10240000) $id44mb = 0;
		$badsectors = Request::post('badsectors', 0, 'integer');
		if ($badsectors < 0 || $badsectors > 100000) $badsectors = 0;
		$hostname = gethostbyaddr($ip);
		if (!is_string($hostname) || $hostname === $ip) {
			$hostname = '';
		}
		$data = Request::post('data', '', 'string');
		// See if we have a lingering session, create statistic entry if so
		if ($uptime < 120) {
			$old = Database::queryFirst('SELECT logintime, lastseen FROM machine WHERE machineuuid = :uuid', array('uuid' => $uuid));
			if ($old !== false && (int)$old['logintime'] !== 0) {
				$sessionLength = $old['lastseen'] - $old['logintime'];
				if ($sessionLength > 0) {
					$start = $old['logintime'];
					if ($start === 0) $start = $NOW;
					Database::exec('INSERT INTO statistic (dateline, typeid, clientip, username, data)'
						. " VALUES (:start, '.session-length', :clientip, '', :length)", array(
							'start'     => $start,
							'clientip'  => $ip,
							'length'    => $sessionLength
					));
				}
			}
		}
		// Create/update machine entry
		Database::exec('INSERT INTO machine '
			. '(machineuuid, macaddr, clientip, firstseen, lastseen, logintime, position, lastboot, realcores, mbram,'
			. ' kvmstate, cpumodel, id44mb, badsectors, data, hostname) VALUES '
			. "(:uuid, :macaddr, :clientip, :firstseen, :lastseen, 0, '', :lastboot, :realcores, :mbram,"
			. ' :kvmstate, :cpumodel, :id44mb, :badsectors, :data, :hostname)'
			. ' ON DUPLICATE KEY UPDATE'
			. ' macaddr = VALUES(macaddr),'
			. ' clientip = VALUES(clientip),'
			. ' lastseen = VALUES(lastseen),'
			. ($uptime < 120 ? ' logintime = 0,' : '')
			. ' lastboot = VALUES(lastboot),'
			. ' realcores = VALUES(realcores),'
			. ' mbram = VALUES(mbram),'
			. ' kvmstate = VALUES(kvmstate),'
			. ' cpumodel = VALUES(cpumodel),'
			. ' id44mb = VALUES(id44mb),'
			. ' badsectors = VALUES(badsectors),'
			. ' data = VALUES(data),'
			. " hostname = If(VALUES(hostname) = '', hostname, VALUES(hostname))", array(
			'uuid'       => $uuid,
			'macaddr'    => $macaddr,
			'clientip'   => $ip,
			'firstseen'  => $NOW,
			'lastseen'   => $NOW,
			'lastboot'   => $NOW - $uptime,
			'realcores'  => $realcores,
			'mbram'      => $mbram,
			'kvmstate'   => $kvmstate,
			'cpumodel'   => $cpumodel,
			'id44mb'     => $id44mb,
			'badsectors' => $badsectors,
			'data'       => $data,
			'hostname'   => $hostname,
		));
	} else if ($type === '~runstate') {
		// Usage (occupied/free)
		$sessionLength = 0;
		$used = Request::post('used', 0, 'integer');
		$old = Database::queryFirst('SELECT logintime, lastseen FROM machine WHERE machineuuid = :uuid', array('uuid' => $uuid));
		if ($old === false) die("Unknown machine.\n");
		settype($old['logintime'], 'integer');
		settype($old['lastseen'], 'integer');
		// Figure out what's happening
		if ($used === 0) {
			// Is not in use
			if ($old['logintime'] !== 0) {
				// Was in use, is free now
				// 1) Log last session length
				if ($NOW - $old['lastseen'] > 610) {
					// Old session timed out - might be caused by hard reboot
					$sessionLength = $old['lastseen'] - $old['logintime'];
				} else {
					$sessionLength = $NOW - $old['logintime'];
				}
			}
			Database::exec('UPDATE machine SET lastseen = UNIX_TIMESTAMP(), logintime = 0 WHERE machineuuid = :uuid', array('uuid' => $uuid));
		} else {
			// Machine is in use
			if ($old['logintime'] !== 0 && $NOW - $old['lastseen'] > 610) {
				// Old session timed out - might be caused by hard reboot
				$sessionLength = $old['lastseen'] - $old['logintime'];
			}
			if ($sessionLength !== 0 || $old['logintime'] === 0) {
				// This event is a start of a new session, rather than an update
				Database::exec('UPDATE machine SET lastseen = UNIX_TIMESTAMP(), logintime = UNIX_TIMESTAMP() WHERE machineuuid = :uuid', array('uuid' => $uuid));
			} else {
				// Nothing changed, simple lastseen update
				Database::exec('UPDATE machine SET lastseen = UNIX_TIMESTAMP() WHERE machineuuid = :uuid', array('uuid' => $uuid));
			}
		}
		// 9) Log last session length if applicable
		if ($sessionLength > 0) {
			$start = $old['logintime'];
			if ($start === 0) $start = $NOW;
			Database::exec('INSERT INTO statistic (dateline, typeid, clientip, username, data)'
				. " VALUES (:start, '.session-length', :clientip, '', :length)", array(
					'start'     => $start,
					'clientip'  => $ip,
					'length'    => $sessionLength
			));
		}
	} elseif ($type === '~poweroff') {
		$old = Database::queryFirst('SELECT logintime, lastseen FROM machine WHERE machineuuid = :uuid', array('uuid' => $uuid));
		if ($old !== false && (int)$old['logintime'] !== 0) {
			$sessionLength = $old['lastseen'] - $old['logintime'];
			if ($sessionLength > 0) {
				$start = $old['logintime'];
				if ($start === 0) $start = $NOW;
				Database::exec('INSERT INTO statistic (dateline, typeid, clientip, username, data)'
					. " VALUES (:start, '.session-length', :clientip, '', :length)", array(
						'start'     => $start,
						'clientip'  => $ip,
						'length'    => $sessionLength
				));
			}
		}
		Database::exec('UPDATE machine SET logintime = 0, lastseen = UNIX_TIMESTAMP() - 600 WHERE machineuuid = :uuid', array('uuid' => $uuid));
	}
	die("OK. (RESULT=0)\n");
}

/*
 * Normal logging
 */

if (!isset($_POST['description'])) die('Missing options..');

$description = $_POST['description'];
$longdesc = '';
if (isset($_POST['longdesc'])) $longdesc = $_POST['longdesc'];

// Spam from IP
$row = Database::queryFirst('SELECT Count(*) AS cnt FROM clientlog WHERE clientip = :client AND dateline + 3600 > UNIX_TIMESTAMP()', array(':client' => $ip));
if ($row !== false && $row['cnt'] > 150) exit(0);


if ($type{0} === '.' && preg_match('#^\[([^\]]+)\]\s*(.*)$#', $description, $out)) {
	// Special case '.'-type:
	Database::exec('INSERT INTO statistic (dateline, typeid, clientip, username, data) VALUES (UNIX_TIMESTAMP(), :type, :client, :username, :data)', array(
		'type'        => $type,
		'client'      => $ip,
		'username'    => $out[1],
		'data'        => $out[2],
	));
	
} else {
	
	Database::exec('INSERT INTO clientlog (dateline, logtypeid, clientip, description, extra) VALUES (UNIX_TIMESTAMP(), :type, :client, :description, :longdesc)', array(
		'type'        => $type,
		'client'      => $ip,
		'description' => $description,
		'longdesc'    => $longdesc,
	));
}

echo "OK.\n";

