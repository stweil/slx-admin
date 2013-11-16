<?php

User::load();

if (!User::hasPermission('superadmin')) die('No permission');

require_once('inc/render.inc.php');

error_log('**URI: '. $_SERVER['REQUEST_URI']);

if (!isset($_REQUEST['id'])) die('No id');
$id = $_REQUEST['id'];

// Progress update only

if (isset($_REQUEST['progress'])) {
	$progress = preg_replace('/[^a-z0-9\-]/', '', $_REQUEST['progress']);
	$pid = (isset($_REQUEST['pid']) ? (int)$_REQUEST['pid'] : 0);
	$log = '/tmp/' . $progress . '.log';
	if (!file_exists($log)) {
		echo Render::parse('exec-error');
		exit(0);
	}
	$lastLines = array();
	$fh = fopen($log, 'r');
	while (!feof($fh)) {
		$line = fgets($fh);
		$lastLines[] = $line;
		if (count($lastLines) > 10) array_shift($lastLines);
	}
	fclose($fh);
	$running = ($pid == 0 || posix_kill($pid, 0));
	echo Render::parse('exec-progress', array('progress' => $progress, 'id' => $id, 'pid' => $pid, 'running' => $running, 'text' => implode('', $lastLines)));
	if (!$running) unlink($log);
	exit(0);
}

// Actual download request
// type ip id

if (!isset($_REQUEST['type'])) die('No type');


$type = $_REQUEST['type'];

switch ($type) {
case 'ipxe':
	if (!isset($_REQUEST['ip'])) die('No IP given');
	$ip = preg_replace('/[^0-9\.]/', '', $_REQUEST['ip']);
	$command = '/opt/openslx/build_ipxe.sh "' . CONFIG_IPXE_DIR . '/last-ip" "' . $ip . '"';
	$conf = Render::parse('txt-ipxeconfig', array(
		'SERVER' => $ip
	));
	if (false === file_put_contents('/opt/openslx/ipxe/ipxelinux.ipxe', $conf)) die('Error writing iPXE Config');
	$conf = Render::parse('txt-pxeconfig', array(
		'SERVER' => $ip,
		'DEFAULT' => 'openslx'
	));
	if (false === file_put_contents(CONFIG_TFTP_DIR . '/pxelinux.cfg/default', $conf)) die('Error writing PXE Menu');
	break;
default:
	die('Invalid exec type');
}

$logfile = 'slx-' . mt_rand() . '-' . time();
error_log('**EXEC: ' . "$command '/tmp/${logfile}.log'");
exec("$command '/tmp/${logfile}.log'", $retstr, $retval);
if ($retval != 0) {
	echo Render::parse('exec-error', array('error' => implode(' // ', $retstr) . ' - ' . $retval));
	exit(0);
}
$pid = 0;
foreach ($retstr as $line) if (preg_match('/PID: (\d+)\./', $line, $out)) $pid = $out[1];
echo Render::parse('exec-progress', array('progress' => $logfile, 'id' => $id, 'pid' => $pid, 'running' => true));

