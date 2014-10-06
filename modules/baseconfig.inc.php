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
			Util::redirect('?do=Main');
		}
		// Build left joins for specific settings
		$joins = '';
		foreach ($this->qry_extra as $item) {
			$joins .= " LEFT JOIN {$item['table']} ";
		}
		// List global config option
		$settings = array();
		$res = Database::simpleQuery('SELECT cat_setting.catid, setting.setting, setting.defaultvalue, setting.permissions, setting.validator, tbl.value
			FROM setting
			INNER JOIN cat_setting USING (catid)
			LEFT JOIN setting_global AS tbl USING (setting)
			ORDER BY cat_setting.sortval ASC, setting.setting ASC');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['description'] = Util::markup(Dictionary::translate('settings/setting', $row['setting']));
			if (is_null($row['value'])) $row['value'] = $row['defaultvalue'];
			$row['item'] = $this->makeInput($row['validator'], $row['setting'], $row['value']);
			$settings[$row['catid']]['settings'][] = $row;
			$settings[$row['catid']]['category_name'] = Dictionary::translate('settings/cat_setting', 'cat_' . $row['catid']);
		}

		Render::addTemplate('baseconfig/_page', array(
			'categories'  => array_values($settings)
		));
	}
	
	/**
	 * Create html snippet for setting, based on given validator
	 * @param type $validator
	 * @return boolean
	 */
	private function makeInput($validator, $setting, $current)
	{
		$parts = explode(':', $validator, 2);
		if ($parts[0] === 'list') {
			$items = explode('|', $parts[1]);
			$ret = '<select name="setting[' . $setting . ']" class="form-control">';
			foreach ($items as $item) {
				if ($item === $current) {
					$ret .= '<option selected="selected">' . $item . '</option>';
				} else {
					$ret .= '<option>' . $item . '</option>';
				}
			}
			return $ret . '</select>';
		}
		// Fallback: single line input
		return '<input type="text" name="setting[' . $setting . ']" class="form-control" size="30" value="' . $current . '">';
	}

}
