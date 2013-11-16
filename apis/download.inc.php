<?php

User::load();

if (!User::hasPermission('superadmin')) die('No permission');

require_once('inc/render.inc.php');

if (!isset($_REQUEST['file'])) die('No file');
if (!isset($_REQUEST['id'])) die('No id');
$file = preg_replace('/[^a-z0-9\.\-_]/is', '', $_REQUEST['file']);
$id = $_REQUEST['id'];

// Progress update only

if (isset($_REQUEST['progress'])) {
	$progress = preg_replace('/[^a-z0-9\-]/', '', $_REQUEST['progress']);
	$pid = (isset($_REQUEST['pid']) ? (int)$_REQUEST['pid'] : 0);
	$log = '/tmp/' . $progress . '.log';
	if (!file_exists($log)) {
		echo Render::parse('download-error', array('file' => $file));
		exit(0);
	}
	$error = false;
	$percent = 0;
	$fh = fopen($log, 'r');
	while (!feof($fh)) {
		$line = fgets($fh);
		if (preg_match('/ ERROR (\d{3}):/', $line, $out)) {
			$error = $out[1];
			break;
		}
		if (preg_match('/ (\d+)% /', $line, $out)) {
			$percent = $out[1];
		}
	}
	fclose($fh);
	if ($error === false && $pid > 0 && $percent != 100 && !posix_kill($pid, 0)) $error = 'Process died - ' . $line;
	if ($error !== false) {
		echo Render::parse('download-error', array('file' => $file, 'code' => $error));
		unlink($log);
		exit(0);
	}
	if ($percent == 100) {
		echo Render::parse('download-complete' ,array('file' => $file));
		unlink($log);
	} else {
		echo Render::parse('download-progress' ,array('file' => $file, 'progress' => $progress, 'id' => $id, 'percent' => $percent, 'pid' => $pid));
	}
	exit(0);
}

// Actual download request

if (!isset($_REQUEST['type'])) die('No type');


$type = $_REQUEST['type'];
$directExec = true;
$overwrite = isset($_REQUEST['exec']);

switch ($type) {
case 'tgz':
	$remote = CONFIG_REMOTE_TGZ;
	$local = CONFIG_TGZ_LIST_DIR;
	break;
case 'ml':
	$remote = CONFIG_REMOTE_ML;
	$local = CONFIG_HTTP_DIR . '/default';
	$directExec = false;
	$overwrite = true;
	break;
default:
	die('Invalid download type');
}
@mkdir($local, 0755, true);

if (file_exists($local . '/' . $file) && !$overwrite) {
	echo Render::parse('download-overwrite', array('file' => $file, 'id' => $id, 'query' => $_SERVER['REQUEST_URI']));
	exit(0);
}

if ($directExec) {
	// Blocking inline download
	$ret = Util::downloadToFile($local . '/' . $file, $remote . '/' . $file, 20, $code);
	if ($ret === false || $code < 200 || $code >= 300) {
		@unlink($local . '/' . $file);
		echo Render::parse('download-error', array('file' => $file, 'remote' => $remote, 'code' => $code));
		exit(0);
	}
	
	// No execution - just return dialog
	echo Render::parse('download-complete', array('file' => $file));
} else {
	// Use WGET
	$logfile = 'slx-' . mt_rand() . '-' . time();
	exec("wget --timeout=10 -O $local/$file -o /tmp/${logfile}.log -b $remote/$file", $retstr, $retval);
	unlink("$local/${file}.md5");
	if ($retval != 0) {
		echo Render::parse('download-error', array('file' => $destination, 'remote' => $source, 'code' => implode(' // ', $retstr) . ' - ' . $retval));
		exit(0);
	}
	$pid = 0;
	foreach ($retstr as $line) if (preg_match('/ (\d+)([,\.\!\:]|$)/', $line, $out)) $pid = $out[1];
	file_put_contents("$local/${file}.lck", $pid . ' ' . $logfile);
	echo Render::parse('download-progress', array('file' => $file, 'progress' => $logfile, 'id' => $id, 'percent' => '0', 'pid' => $pid));
}

