<?php

class Page_BaseConfig extends Page
{
	private $qry_extra = array();

	protected function doPreprocess()
	{
		User::load();

		// Determine if we're setting global, distro or pool
		if (isset($_REQUEST['distroid'])) {
			// TODO: Everything
			$this->qry_extra[] = array(
				'name'  => 'distroid',
				'value' => (int)$_REQUEST['distroid'],
				'table' => 'setting_distro',
			);
			if (isset($_REQUEST['poolid'])) {
				$this->qry_extra[] = array(
					'name'  => 'poolid',
					'value' => (int)$_REQUEST['poolid'],
					'table' => 'setting_pool',
				);
			}
		}

		if (isset($_POST['setting']) && is_array($_POST['setting'])) {
			if (User::hasPermission('superadmin')) {
				// Build variables for specific sub-settings
				$qry_insert = '';
				$qry_values = '';
				foreach ($this->qry_extra as $item) {
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
						$this->qry_extra + array(
							'key'      => $key,
							'value'    => $value,
						)
					);
				}
				Message::addSuccess('settings-updated');
				Util::redirect('?do=BaseConfig');
			}
		}
	}

	protected function doRender()
	{
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			return;
		}
		// Build left joins for specific settings
		$joins = '';
		foreach ($this->qry_extra as $item) {
			$joins .= " LEFT JOIN {$item['table']} ";
		}
		// List global config option
		$settings = array();
		$res = Database::simpleQuery('SELECT cat_setting.name AS category_name, setting.setting, setting.defaultvalue, setting.permissions, setting.description, tbl.value
			FROM setting
			INNER JOIN cat_setting USING (catid)
			LEFT JOIN setting_global AS tbl USING (setting)
			ORDER BY cat_setting.sortval ASC, setting.setting ASC'); // TODO: Add setting groups and sort order
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['description'] = Util::markup($row['description']);
			if (is_null($row['value'])) $row['value'] = $row['defaultvalue'];
			$row['big'] = false;
			$settings[$row['category_name']]['settings'][] = $row;
			$settings[$row['category_name']]['category_name'] = $row['category_name'];
		}
		$settings = array_values($settings);
		Render::addTemplate('page-baseconfig', array(
			'categories'  => $settings
		));
	}

}
