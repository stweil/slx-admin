<?php

User::load();

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
	$files = array();
	foreach (glob(CONFIG_TGZ_LIST_DIR . '/*.tgz') as $file) {
		$files[] = array(
			'file' => $file
		);
	}
	Render::addTemplate('page-tgz-list', array('files' => $files));
}

function list_remote_configs()
{
	if (!User::hasPermission('superadmin')) {
		Message::addError('no-permission');
		return;
	}
	$data = Util::download(CONFIG_REMOTE_TGZ . '/list.php', 4, $code);
	if ($code !== 200) {
		Message::addError('remote-timeout', CONFIG_REMOTE_TGZ);
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

