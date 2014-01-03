<?php

User::load();

// Determine if we're setting global, distro or pool
$qry_extra = array();
if (isset($_REQUEST['distroid'])) {
	// TODO: Everything
	$qry_extra[] = array(
		'name'  => 'distroid',
		'value' => (int)$_REQUEST['distroid'],
		'table' => 'setting_distro',
	);
	if (isset($_REQUEST['poolid'])) {
		$qry_extra[] = array(
			'name'  => 'poolid',
			'value' => (int)$_REQUEST['poolid'],
			'table' => 'setting_pool',
		);
	}
}


if (isset($_POST['setting']) && is_array($_POST['setting'])) {
	if (User::hasPermission('superadmin')) {
		if (Util::verifyToken()) {
			// Build variables for specific sub-settings
			$qry_insert = '';
			$qry_values = '';
			foreach ($qry_extra as $item) {
				$qry_insert = ', ' . $item['name'];
				$qry_values = ', :' . $item['name'];
			}
			// Load all existing config options to validate input
			$res = Database::simpleQuery('SELECT setting, validator FROM setting');
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$key = $row['setting'];
				$validator = $row['validator'];
				$input = (isset($_POST['setting'][$key]) ? $_POST['setting'][$key] : '');
				// Validate data first!
				$value = Validator::validate($validator, $input);
				if ($value === false) {
					Message::addWarning('value-invalid', $key, $input);
					continue;
				}
				// Now put into DB
				Database::exec("INSERT INTO setting_global (setting, value $qry_insert)
					VALUES (:key, :value $qry_values)
					ON DUPLICATE KEY UPDATE value = :value",
					$qry_extra + array(
						'key'      => $key,
						'value'    => $value,
					)
				);
			}
			Message::addSuccess('settings-updated');
			Util::redirect('?do=baseconfig');
		}
	}
}

function render_module()
{
	if (!User::hasPermission('superadmin')) {
		Message::addError('no-permission');
		return;
	}
	// Build left joins for specific settings
	global $qry_extra;
	$joins = '';
	foreach ($qry_extra as $item) {
		$joins .= " LEFT JOIN ${item['table']} ";
	}
	// List global config option
	$settings = array();
	$res = Database::simpleQuery('SELECT setting.setting, setting.defaultvalue, setting.permissions, setting.description, tbl.value
		FROM setting
		LEFT JOIN setting_global AS tbl USING (setting)
		ORDER BY setting ASC'); // TODO: Add setting groups and sort order
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$row['description'] = Util::markup($row['description']);
		if (is_null($row['value'])) $row['value'] = $row['defaultvalue'];
		$row['big'] = false;
		$settings[] = $row;
	}
	Render::addTemplate('page-baseconfig', array(
		'settings'    => $settings,
		'token'       => Session::get('token'),
	));
}

