<?php

User::load();

// Determine if we're setting global, distro or pool
if (isset($_REQUEST['distro'])) {
	// TODO: Everything
	$qry_insert = ', distroid';
	$qry_values = ', :distroid';
	$qry_distroid = (int)$_REQUEST['distro'];
	if (isset($_REQUEST['pool'])) {
		// TODO: Everything
		$qry_insert .= ', poolid';
		$qry_values .= ', :poolid';
		$qry_poolid .= (int)$_REQUEST['pool'];
	}
} else {
	$qry_insert = '';
	$qry_values = '';
	$qry_distroid = '';
	$qry_poolid = '';
}


if (isset($_POST['setting']) && is_array($_POST['setting'])) {
	if (User::hasPermission('superadmin')) {
		if (Util::verifyToken()) {
			// Load all existing config options to validate input
			$settings = array();
			$res = Database::simpleQuery('SELECT setting FROM setting');
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$settings[$row['setting']] = true; // will contain validation regex at some point
			}
			foreach (array_keys($settings) as $key) {
				$value = (isset($_POST['setting'][$key]) ? $_POST['setting'][$key] : '');
				// use validation regex here
				Database::exec("INSERT INTO setting_global (setting, value $qry_insert) VALUES (:key, :value $qry_values) ON DUPLICATE KEY UPDATE value = :value", array(
					'key'      => $key,
					'value'    => $value,
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
	$settings = array();
	$res = Database::simpleQuery('SELECT setting.setting, setting.defaultvalue, setting.permissions, setting.description, tbl.value
		FROM setting
		LEFT JOIN setting_global AS tbl USING (setting)
		ORDER BY setting ASC'); // TODO: Add setting groups and sort order
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$row['description'] = Util::markup($row['description']);
		$row['big'] = false;
		$settings[] = $row;
	}
	Render::addTemplate('page-baseconfig', array(
		'settings'    => $settings,
		'token'       => Session::get('token'),
	));
}

