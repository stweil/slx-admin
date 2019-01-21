<?php

function logstats()
{
	$NOW = time();
	$cutoff = $NOW - 86400 * 30;
	$join = $where = '';
	if (Module::get('runmode') !== false) {
		$join = 'LEFT JOIN runmode r USING (machineuuid)';
		$where = 'AND (r.isclient IS NULL OR r.isclient <> 0)';
	}
	$known = Database::queryFirst("SELECT Count(*) AS val FROM machine m $join WHERE m.lastseen > $cutoff $where");
	$on = Database::queryFirst("SELECT Count(*) AS val FROM machine m $join WHERE m.state IN ('IDLE', 'OCCUPIED') $where");
	$used = Database::queryFirst("SELECT Count(*) AS val FROM machine m $join WHERE m.state = 'OCCUPIED' $where");
	Database::exec("INSERT INTO statistic (dateline, typeid, clientip, username, data) VALUES (:now, '~stats', '', '', :vals)", array(
		'now' => $NOW,
		'vals' => $known['val'] . '#' . $on['val'] . '#' . $used['val'],
	));
}

function state_cleanup()
{
	// Fix online state of machines that crashed
	$standby = time() - 86400 * 2; // Reset standby machines after two days
	$on = time() - 610; // Reset others after ~10 minutes
	// Query for logging
	$res = Database::simpleQuery("SELECT machineuuid, clientip, state FROM machine WHERE lastseen < If(state = 'STANDBY', $standby, $on) AND state <> 'OFFLINE'");
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		Database::exec('INSERT INTO clientlog (dateline, logtypeid, clientip, machineuuid, description, extra)
					VALUES (UNIX_TIMESTAMP(), :type, :client, :uuid, :description, :longdesc)', array(
			'type'        => 'machine-mismatch-cron',
			'client'      => $row['clientip'],
			'description' => 'Client timed out, last known state is ' . $row['state'],
			'longdesc'    => '',
			'uuid'        => $row['machineuuid'],
		));
	}
	// Update -- yes this is not atomic. Should be sufficient for simple warnings though.
	Database::exec("UPDATE machine SET state = 'OFFLINE' WHERE lastseen < If(state = 'STANDBY', $standby, $on) AND state <> 'OFFLINE'");
}

state_cleanup();

logstats();

if (mt_rand(1, 10) === 1) {
	Database::exec("DELETE FROM statistic WHERE (UNIX_TIMESTAMP() - 86400 * 190) > dateline");
	if (mt_rand(1, 100) === 1) {
		Database::exec("OPTIMIZE TABLE statistic");
	}
}
if (mt_rand(1, 10) === 1) {
	Database::exec("DELETE FROM machine WHERE (UNIX_TIMESTAMP() - 86400 * 365) > lastseen");
	if (mt_rand(1, 100) === 1) {
		Database::exec("OPTIMIZE TABLE machine");
	}
}
