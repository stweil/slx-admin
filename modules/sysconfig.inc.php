<?php

User::load();

if (isset($_POST['action']) && $_POST['action'] === 'upload') {
	if (!Util::verifyToken()) {
		Util::redirect('?do=sysconfig');
	}
	if (!User::hasPermission('superadmin')) {
		Message::addError('no-permission');
		Util::redirect('?do=sysconfig');
	}
	if (!isset($_FILES['customtgz'])) {
		Message::addError('missing-file');
		Util::redirect('?do=sysconfig');
	}
	$dest = $_FILES['customtgz']['name'];
	$dest = preg_replace('/[^a-z0-9\-_]/', '', $dest);
	$dest = substr($dest, 0, 30);
	if (substr($dest, -3) === 'tgz') $dest = substr($dest, 0, -3);
	$dest .= '.tgz';
	# TODO: Validate its a (compressed) tar?
	if (move_uploaded_file($_FILES['customtgz']['tmp_name'], CONFIG_TGZ_LIST_DIR . '/' . $dest)) {
		Message::addSuccess('upload-complete', $dest);
	} else {
		Message::addError('upload-failed', $dest);
	}
	Util::redirect('?do=sysconfig');
}

if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'activate') {
	if (!Util::verifyToken()) {
		Util::redirect('?do=sysconfig');
	}
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

function render_module()
{
	if (!isset($_REQUEST['action'])) $_REQUEST['action'] = 'list';
	switch ($_REQUEST['action']) {
	case 'remotelist':
		list_remote_configs();
		break;
	case 'list':
		list_configs();
		break;
	default:
		Message::addError('invalid-action', $_REQUEST['action']);
		break;
	}
}

function list_configs()
{
	if (!User::hasPermission('superadmin')) {
		Message::addError('no-permission');
		return;
	}
	$current = '<none>';
	if (file_exists(CONFIG_HTTP_DIR . '/default/config.tgz')) $current = realpath(CONFIG_HTTP_DIR . '/default/config.tgz');
	$files = array();
	foreach (glob(CONFIG_TGZ_LIST_DIR . '/*.tgz') as $file) {
		$files[] = array(
			'file' => basename($file),
			'current' => ($current === realpath($file))
		);
	}
	Render::addTemplate('page-tgz-list', array('files' => $files, 'token' => Session::get('token')));
}

function list_remote_configs()
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

