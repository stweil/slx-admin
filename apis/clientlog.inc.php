<?php

if (empty($_POST['type'])) die('Missing options.');
$type = mb_strtolower($_POST['type']);

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') $ip = substr($ip, 7);

/*
 * Power/hw/usage stats
 */

if ($type{0} === '~') {
	$uuid = Request::post('uuid', '', 'string');
	$NOW = time();
	if ($type === '~poweron') {
	$macaddr = Request::post('macaddr', '', 'string');
	$uptime = Request::post('uptime', '', 'integer');
	if (strlen($uuid) !== 36) die("Invalid UUID.\n");
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
	$hostname = gethostbyaddr($ip);
	if (!is_string($hostname) || $hostname === $ip) {
		$hostname = '';
	}
	$data = Request::post('data', '', 'string');
		Database::exec('INSERT INTO machine '
			. '(machineuuid, macaddr, clientip, firstseen, lastseen, logintime, position, lastboot, realcores, mbram,'
			. ' kvmstate, cpumodel, id44mb, data, hostname) VALUES '
			. "(:uuid, :macaddr, :clientip, :firstseen, :lastseen, 0, '', :lastboot, :realcores, :mbram,"
			. ' :kvmstate, :cpumodel, :id44mb, :data, :hostname)'
			. ' ON DUPLICATE KEY UPDATE'
			. ' macaddr = VALUES(macaddr),'
			. ' clientip = VALUES(clientip),'
			. ' lastseen = VALUES(lastseen),'
			. ' logintime = 0,'
			. ' lastboot = VALUES(lastboot),'
			. ' realcores = VALUES(realcores),'
			. ' mbram = VALUES(mbram),'
			. ' kvmstate = VALUES(kvmstate),'
			. ' cpumodel = VALUES(cpumodel),'
			. ' id44mb = VALUES(id44mb),'
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
			'data'       => $data,
			'hostname'   => $hostname,
		));
	}
	die("OK.\n");
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

