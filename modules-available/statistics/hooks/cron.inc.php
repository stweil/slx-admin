<?php

function logstats() {
	$NOW = time();
	$cutoff = $NOW - 86400 * 30;
	$online = $NOW - 610;
	$known = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE lastseen > $cutoff");
	$on = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE lastseen > $online");
	$used = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE lastseen > $online AND logintime <> 0");
	Database::exec("INSERT INTO statistic (dateline, typeid, clientip, username, data) VALUES (:now, '~stats', '', '', :vals)", array(
		'now' => $NOW,
		'vals' => $known['val'] . '#' . $on['val'] . '#' . $used['val'],
	));
}

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
