<?php

/*
 * JumpHost availability test, 5 times a day...
 */
if (in_array((int)date('G'), [6, 7, 9, 12, 15]) && in_array(date('i'), ['00', '01', '02', '03'])) {
	$res = Database::simpleQuery('SELECT hostid, host, port, username, sshkey, script FROM reboot_jumphost');
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		RebootControl::wakeViaJumpHost($row, '255.255.255.255', [['macaddr' => '00:11:22:33:44:55']]);
	}
}

/*
 * Client reachability test -- can be disabled
 */
if (mt_rand(1, 2) !== 1 || Property::get(RebootControl::KEY_AUTOSCAN_DISABLED))
	return;

class Stuff
{
	public static $subnets;
}

function destSawPw($destTask, $destMachine, $passwd)
{
	return strpos($destTask['data']['result'][$destMachine['machineuuid']]['stdout'], "passwd=$passwd") !== false;
}

function spawnDestinationListener($dstid, &$destMachine, &$destTask, &$destDeadline)
{
	$destMachines = Stuff::$subnets[$dstid];
	cron_log(count($destMachines) . ' potential destination machines for subnet ' . $dstid);
	shuffle($destMachines);
	$destMachines = array_slice($destMachines, 0, 3);
	$destTask = $destMachine = false;
	$destDeadline = 0;
	foreach ($destMachines as $machine) {
		cron_log("Trying to use {$machine['clientip']} as listener for " . long2ip($machine['bcast']));
		$destTask = RebootControl::runScript([$machine], "echo 'Running-MARK'\nbusybox timeout -t 8 jawol -v -l", 10);
		Taskmanager::release($destTask);
		$destDeadline = time() + 10;
		if (!Taskmanager::isRunning($destTask))
			continue;
		sleep(2); // Wait a bit and re-check job is running; only then proceed with this host
		$destTask = Taskmanager::status($destTask);
		cron_log("....is {$destTask['statusCode']} {$machine['machineuuid']}");
		if (Taskmanager::isRunning($destTask)
			&& strpos($destTask['data']['result'][$machine['machineuuid']]['stdout'], 'Running-MARK') !== false) {
			$destMachine = $machine;
			break; // GOOD TO GO
		}
		cron_log(print_r($destTask, true));
		cron_log("Dest isn't running or didn't have MARK in output, trying another one...");
	}
}

function testClientToClient($srcid, $dstid)
{
	$sourceMachines = Stuff::$subnets[$srcid];
	// Start listener on destination
	spawnDestinationListener($dstid, $destMachine, $destTask, $destDeadline);
	if ($destMachine === false || !Taskmanager::isRunning($destTask))
		return false; // No suitable dest-host found
	// Find a source host
	$passwd = sprintf('%02x:%02x:%02x:%02x:%02x:%02x', mt_rand(0, 255), mt_rand(0, 255),
		mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
	shuffle($sourceMachines);
	$sourceMachines = array_slice($sourceMachines, 0, 3);
	cron_log("Running sending task on "
		. implode(', ', array_map(function($item) { return $item['clientip']; }, $sourceMachines)));
	$sourceTask = RebootControl::wakeViaClient($sourceMachines, $destMachine['macaddr'], $destMachine['bcast'], $passwd);
	Taskmanager::release($sourceTask);
	if (!Taskmanager::isRunning($sourceTask)) {
		cron_log('Failed to launch task for source hosts...');
		return false;
	}
	cron_log('Waiting for testing tasks to finish...');
	// Loop as long as destination task and source task is running and we didn't see the pw at destination yet
	while (Taskmanager::isRunning($destTask) && Taskmanager::isRunning($sourceTask)
			&& !destSawPw($destTask, $destMachine, $passwd) && $destDeadline > time()) {
		$sourceTask = Taskmanager::status($sourceTask);
		usleep(250000);
		$destTask = Taskmanager::status($destTask);
	}
	cron_log($destTask['data']['result'][$destMachine['machineuuid']]['stdout']);
	// Final moment: did dest see the packets from src? Determine this by looking for the generated password
	if (destSawPw($destTask, $destMachine, $passwd))
		return 1; // Found pw
	return 0; // Nothing :-(
}

function testServerToClient($dstid)
{
	spawnDestinationListener($dstid, $destMachine, $destTask, $destDeadline);
	if ($destMachine === false || !Taskmanager::isRunning($destTask))
		return false; // No suitable dest-host found
	$passwd = sprintf('%02x:%02x:%02x:%02x:%02x:%02x', mt_rand(0, 255), mt_rand(0, 255),
		mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
	cron_log('Sending WOL packets from Sat Server...');
	$task = RebootControl::wakeDirectly($destMachine['macaddr'], $destMachine['bcast'], $passwd);
	usleep(200000);
	$destTask = Taskmanager::status($destTask);
	if (!destSawPw($destTask, $destMachine, $passwd) && !Taskmanager::isTask($task))
		return false;
	cron_log('Waiting for receive on destination...');
	$task = Taskmanager::status($task);
	if (!destSawPw($destTask, $destMachine, $passwd)) {
		$task = Taskmanager::waitComplete($task, 2000);
		$destTask = Taskmanager::status($destTask);
	}
	cron_log($destTask['data']['result'][$destMachine['machineuuid']]['stdout']);
	if (destSawPw($destTask, $destMachine, $passwd))
		return 1;
	return 0;
}

/**
 * Take test result, turn into "next check" timestamp
 */
function resultToTime($result)
{
	if ($result === false) {
		// Temporary failure -- couldn't run at least one destination and one source task
		$next = 7200; // 2 hours
	} elseif ($result === 0) {
		// Test finished, subnet not reachable
		$next = 86400 * 7; // a week
	} else {
		// Test finished, reachable
		$next = 86400 * 30; // a month
	}
	return time() + round($next * mt_rand(90, 133) / 100);
}

/*
 *
 */

// First, cleanup: delete orphaned subnets that don't exist anymore, or don't have any clients using our server
$cutoff = strtotime('-180 days');
Database::exec('DELETE FROM reboot_subnet WHERE fixed = 0 AND lastseen < :cutoff', ['cutoff' => $cutoff]);

// Get machines running, group by subnet
$cutoff = time() - 301; // Really only the ones that didn't miss the most recent update
$res = Database::simpleQuery("SELECT s.subnetid, s.end AS bcast, m.machineuuid, m.clientip, m.macaddr
	FROM reboot_subnet s
	INNER JOIN machine m ON (
		(m.state = 'IDLE' OR m.state = 'OCCUPIED')
			AND
		(m.lastseen >= $cutoff)
	 		AND
		(INET_ATON(m.clientip) BETWEEN s.start AND s.end)
	)");

//cron_log('Machine: ' . $res->rowCount());

if ($res->rowCount() === 0)
	return;

Stuff::$subnets = [];
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	if (!isset(Stuff::$subnets[$row['subnetid']])) {
		Stuff::$subnets[$row['subnetid']] = [];
	}
	Stuff::$subnets[$row['subnetid']][] = $row;
}

$task = Taskmanager::submit('DummyTask', []);
$task = Taskmanager::waitComplete($task, 4000);
if (!Taskmanager::isFinished($task)) {
	cron_log('Task manager down. Doing nothing.');
	return; // No :-(
}
unset($task);

/*
 * Try server to client
 */

$res = Database::simpleQuery("SELECT subnetid FROM reboot_subnet
		WHERE subnetid IN (:active) AND nextdirectcheck < UNIX_TIMESTAMP() AND fixed = 0
		ORDER BY nextdirectcheck ASC LIMIT 10", ['active' => array_keys(Stuff::$subnets)]);
cron_log('Direct checks: ' . $res->rowCount() . ' (' . implode(', ', array_keys(Stuff::$subnets)) . ')');
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	$dst = (int)$row['subnetid'];
	cron_log('Direct check for subnetid ' . $dst);
	$result = testServerToClient($dst);
	$next = resultToTime($result);
	if ($result === false) {
		Database::exec('UPDATE reboot_subnet
			SET nextdirectcheck = :nextcheck
			WHERE subnetid = :dst', ['nextcheck' => $next, 'dst' => $dst]);
	} else {
		Database::exec('UPDATE reboot_subnet
			SET nextdirectcheck = :nextcheck, isdirect = :isdirect
			WHERE subnetid = :dst', ['nextcheck' => $next, 'isdirect' => $result, 'dst' => $dst]);
	}
}

/*
 * Try client to client
 */

// Query all possible combos
$combos = [];
foreach (Stuff::$subnets as $src => $_) {
	$src = (int)$src;
	foreach (Stuff::$subnets as $dst => $_) {
		$dst = (int)$dst;
		if ($src !== $dst) {
			$combos[] = [$src, $dst];
		}
	}
}

// Check subnet to subnet
if (count($combos) > 0) {
	$res = Database::simpleQuery("SELECT ss.subnetid AS srcid, sd.subnetid AS dstid
	FROM reboot_subnet ss
	INNER JOIN reboot_subnet sd ON ((ss.subnetid, sd.subnetid) IN (:combos) AND sd.fixed = 0)
	LEFT JOIN reboot_subnet_x_subnet sxs ON (ss.subnetid = sxs.srcid AND sd.subnetid = sxs.dstid)
	WHERE sxs.nextcheck < UNIX_TIMESTAMP() OR sxs.nextcheck IS NULL
	ORDER BY sxs.nextcheck ASC
	LIMIT 10", ['combos' => $combos]);
	cron_log('C2C checks: ' . $res->rowCount());
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$src = (int)$row['srcid'];
		$dst = (int)$row['dstid'];
		$result = testClientToClient($src, $dst);
		$next = resultToTime($result);
		Database::exec('INSERT INTO reboot_subnet_x_subnet (srcid, dstid, reachable, nextcheck)
				VALUES (:srcid, :dstid, :reachable, :nextcheck)
				ON DUPLICATE KEY UPDATE ' . ($result === false ? '' : 'reachable = VALUES(reachable),') . ' nextcheck = VALUES(nextcheck)',
			['srcid' => $src, 'dstid' => $dst, 'reachable' => (int)$result, 'nextcheck' => $next]);
	}
}
