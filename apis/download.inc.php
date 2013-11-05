<?php

User::load();

if (!User::hasPermission('superadmin')) die('No permission');
if (!isset($_REQUEST['type'])) die('No type');
if (!isset($_REQUEST['file'])) die('No file');
if (!isset($_REQUEST['id'])) die('No id');

require_once('inc/render.inc.php');

$type = $_REQUEST['type'];
$file = preg_replace('/[^a-z0-9\.\-_]/is', '', $_REQUEST['file']);
$id = $_REQUEST['id'];

switch ($type) {
case 'tgz':
	$remote = CONFIG_REMOTE_TGZ;
	$local = CONFIG_TGZ_LIST_DIR;
	break;
default:
	die('Invalid download type');
}

if (file_exists($local . '/' . $file) && !isset($_REQUEST['exec'])) {
	echo 'FILE EXISTS LOCALLY I SHOULD ASK FOR OVERWRITE NOW';
	exit(0);
}

sleep(2);

// No execution - just return dialog
echo Render::parse('download-complete', array('file' => $file));

