<?php

$NOW = time();
$cutoff = $NOW - 86400*90;

$res = Database::simpleQuery("SELECT m.machineuuid, m.locationid, m.macaddr, m.clientip, m.lastseen, m.logintime, m.mbram,"
	. " m.kvmstate, m.cpumodel, m.systemmodel, m.id44mb, m.badsectors, m.hostname, GROUP_CONCAT(s.locationid) AS locs"
	. " FROM machine m"
	. " LEFT JOIN subnet s ON (INET_ATON(m.clientip) BETWEEN s.startaddr AND s.endaddr)"
	. " WHERE m.lastseen > $cutoff"
	. " GROUP BY m.machineuuid");

$return = array(
	'now' => $NOW,
	'clients' => array(),
	'locations' => Location::getLocationsAssoc()
);
while ($client = $res->fetch(PDO::FETCH_ASSOC)) {
	if ($NOW - $client['lastseen'] > 610) {
		$client['state'] = 'OFF';
	} elseif ($client['logintime'] == 0) {
		$client['state']  = 'IDLE';
	} else {
		$client['state'] = 'OCCUPIED';
	}
	$return['clients'][] = $client;
}

die(json_encode($return));