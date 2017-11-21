<?php

if (!Dnbd3::isEnabled()) return;

if (!Dnbd3::hasNfsFallback()) {
	ConfigHolder::add("SLX_VM_NFS", false, 1000);
	ConfigHolder::add("SLX_VM_NFS_USER", false, 1000);
	ConfigHolder::add("SLX_VM_NFS_PASSWD", false, 1000);
}

// Locations from closest to furthest (order)
$locations = ConfigHolder::get('SLX_LOCATIONS');
if ($locations === false) {
	$locationIds = [0];
} else {
	$locationIds = explode(' ', $locations);
	if (empty($locationIds)) {
		$locationIds[] = 0;
	}
}

$res = Database::simpleQuery('SELECT s.fixedip, m.clientip, sxl.locationid FROM dnbd3_server s
		LEFT JOIN machine m USING (machineuuid)
		LEFT JOIN dnbd3_server_x_location sxl USING (serverid)
		WHERE sxl.locationid IS NULL OR sxl.locationid IN (:lids)', array('lids' => $locationIds));
// Lookup of priority - first index (0) will be closest location in chain
$locationsAssoc = array_flip($locationIds);
$servers = array();
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	if ($row['fixedip'] === '<self>') {
		$row['fixedip'] = Property::getServerIp();
		$defPrio = 2000;
	} else {
		$defPrio = 1000;
	}
	$ip = $row['clientip'] ? $row['clientip'] : $row['fixedip'];
	if ($defPrio === 1000 && is_null($row['locationid'])) {
		$serverLoc = Location::getFromIp($ip);
		if ($serverLoc !== false) {
			$row['locationid'] = $serverLoc;
		}
	}
	$old = isset($servers[$ip]) ? $servers[$ip] : $defPrio;
	if (is_null($row['locationid']) || !isset($locationsAssoc[$row['locationid']])) {
		$servers[$ip] = min($defPrio, $old) . '.' . mt_rand();
	} else {
		$servers[$ip] = min($locationsAssoc[$row['locationid']], $old) . '.' . mt_rand();
	}
}

asort($servers, SORT_NUMERIC | SORT_ASC);
ConfigHolder::add('SLX_DNBD3_SERVERS', implode(' ', array_keys($servers)));
ConfigHolder::add('SLX_VM_DNBD3', 'yes');
