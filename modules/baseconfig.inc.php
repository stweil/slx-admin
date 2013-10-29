<?php

User::load();

if (isset($_POST['setting']) && is_array($_POST['setting'])) {
	if (User::hasPermission('superadmin')) {
		if (Util::verifyToken()) {
			foreach ($_POST['setting'] as $key => $value) {
				Database::exec('UPDATE setting_global SET setting_global.value = :value WHERE setting_global.setting = :key LIMIT 1', array(
					'key'    => $key,
					'value'  => $value,
				));
			}
			Message::addSuccess('settings-updated');
		}
	}
}

function render_module()
{
	if (!User::hasPermission('superadmin')) {
		Message::addError('no-permission');
		return;
	}
	// List global config option
	$rows = array();
	$res = Database::simpleQuery('SELECT setting.setting, setting_global.value, setting.permissions, setting.description
		FROM setting
		INNER JOIN setting_global USING (setting)
		ORDER BY setting.setting ASC');
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$row['description'] = Util::markup($row['description']);
		$row['big'] = false;
		$rows[] = $row;
	}
	Render::addTemplate('page-baseconfig', array(
		'settings'    => $rows,
		'token'       => Session::get('token'),
	));
}

