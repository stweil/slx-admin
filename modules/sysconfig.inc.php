<?php

/*
@include_once('Archive/Tar.php');
if (!class_exists('Archive_Tar')) {
	Message::addError('Broken php installation: pear extension Archive_Tar missing!');
	Util::redirect('?do=main');
}
 */

User::load();

// Read request vars
$action = Request::any('action', 'list');
$step = Request::any('step', 0);
$nextStep = $step + 1;

// Action: "addmodule" (upload new module)
if ($action === 'addmodule') {
	require_once 'modules/sysconfig/addmodule.inc.php';
	$handler = AddModule_Base::get($step);
	$handler->preprocess();
}

// Action "activate" (set sysconfig as active)
if ($action === 'activate') {
	if (!User::hasPermission('superadmin')) {
		Message::addError('no-permission');
		Util::redirect('?do=sysconfig');
	}
	if (!isset($_REQUEST['file'])) {
		Message::addError('missing-file');
		Util::redirect('?do=sysconfig');
	}
	$file = preg_replace('/[^a-z0-9\-_\.]/', '', $_REQUEST['file']);
	$path = CONFIG_TGZ_LIST_DIR . '/' . $file;
	if (!file_exists($path)) {
		Message::addError('invalid-file', $file);
		Util::redirect('?do=sysconfig');
	}
	mkdir(CONFIG_HTTP_DIR . '/default', 0755, true);
	$linkname = CONFIG_HTTP_DIR . '/default/config.tgz';
	@unlink($linkname);
	if (file_exists($linkname)) Util::traceError('Could not delete old config.tgz link!');
	if (!symlink($path, $linkname)) Util::traceError("Could not symlink to $path at $linkname!");
	Message::addSuccess('config-activated');
	Util::redirect('?do=sysconfig');
}

/**
 * Render module; called by main script when this module page should render
 * its content.
 */
function render_module()
{
	global $action, $handler;
	switch ($action) {
	case 'addmodule':
		$handler->render();
		break;
	case 'list':
		rr_list_configs();
		break;
	default:
		Message::addError('invalid-action', $_REQUEST['action']);
		break;
	}
}

function rr_list_configs()
{
	if (!User::hasPermission('superadmin')) {
		Message::addError('no-permission');
		return;
	}
	$res = Database::simpleQuery("SELECT title FROM configtgz_module ORDER BY title ASC");
	$modules = array();
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$modules[] = array(
			'module' => $row['title']
		);
	}
	Render::addTemplate('page-sysconfig-main', array('modules' => $modules, 'token' => Session::get('token')));
}

function rr_list_remote_configs()
{
	if (!User::hasPermission('superadmin')) {
		Message::addError('no-permission');
		return;
	}
	$data = Util::download(CONFIG_REMOTE_TGZ . '/list.php', 4, $code);
	if ($code !== 200) {
		Message::addError('remote-timeout', CONFIG_REMOTE_TGZ . '/list.php', $code);
		return;
	}
	$list = json_decode($data, true);
	if (!is_array($list)) {
		Message::addError('remote-parse-failed', $data);
		return;
	}
	$id = 0;
	foreach ($list as &$item) {
		$item['id'] = 'download' . (++$id);
	}
	Render::addTemplate('page-remote-tgz-list', array('files' => $list));
}
