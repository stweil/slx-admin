<?php

if (Dnbd3::isEnabled()) {
	$res = Database::simpleQuery('SELECT s.fixedip, s.lastseen AS dnbd3lastseen, s.errormsg, m.clientip, m.hostname
			FROM dnbd3_server s
			LEFT JOIN machine m USING (machineuuid)
			WHERE errormsg IS NOT NULL');

	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$error = $row['errormsg'] ? $row['errormsg'] : '<unknown error>';
		$lastSeen = date('d.m.Y H:i', $row['dnbd3lastseen']);
		if ($row['fixedip'] === '<self>') {
			Message::addError('dnbd3.main-dnbd3-unreachable', true, $error, $lastSeen);
			continue;
		}
		if (!is_null($row['fixedip'])) {
			$ip = $row['fixedip'];
		} else {
			$ip = $row['clientip'] . '/' . $row['hostname'];
		}
		Message::addWarning('dnbd3.dnbd3-proxy-unreachable', true, $ip, $error, $lastSeen);
	}

	unset($res);
}
