<?php

function logstats()
{
	$NOW = time();
	$cutoff = $NOW - 86400 * 30;
	$known = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE lastseen > $cutoff");
	$on = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE state IN ('IDLE', 'OCCUPIED')");
	$used = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE state = 'OCCUPIED'");
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
