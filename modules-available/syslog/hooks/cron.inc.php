<?php

if (mt_rand(1, 10) === 1) {
	// Prune old entries
	Database::exec("DELETE FROM clientlog WHERE (UNIX_TIMESTAMP() - 86400 * 190) > dateline");
	// Anonymize if requested
	$days = Property::get('syslog.anon-days', 0);
	if ($days > 0) {
		$cutoff = time() - ($days * 86400);
		Database::exec("UPDATE clientlog SET description = '[root] User logged in'
				WHERE $cutoff > dateline AND logtypeid = 'session-open' AND description NOT LIKE '[root] User %'");
		Database::exec("UPDATE clientlog SET description = '[root] User logged out'
				WHERE $cutoff > dateline AND logtypeid = 'session-close' AND description NOT LIKE '[root] User %'");
		Database::exec("UPDATE clientlog SET description = '-', extra = ''
				WHERE $cutoff > dateline AND description NOT LIKE '-'
				AND logtypeid NOT IN ('session-open', 'session-close', 'idleaction-busy', 'partition-temp',
					'partition-swap', 'smartctl-realloc', 'vmware-netifup', 'vmware-insmod', 'firewall-script-apply',
					'mount-vm-tmp-fail')");
		if (Module::get('statistics') !== false) {
			Database::exec("UPDATE statistic SET username = 'anonymous'
					WHERE $cutoff > dateline AND username NOT LIKE 'anonymous' AND username NOT LIKE ''");
			Database::exec("UPDATE machine SET currentuser = NULL
					WHERE $cutoff > lastseen AND currentuser IS NOT NULL");
		}
	}
	if (mt_rand(1, 100) === 1) {
		Database::exec("OPTIMIZE TABLE clientlog");
	}
}