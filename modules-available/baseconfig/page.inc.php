<?php

class Page_BaseConfig extends Page
{
	private $qry_extra = array();

	protected function doPreprocess()
	{
		User::load();

		// Determine if we're setting global, distro or location
		if (isset($_REQUEST['distroid'])) {
			// TODO: Everything
			$this->qry_extra[] = array(
				'name'  => 'distroid',
				'value' => (int)$_REQUEST['distroid'],
				'table' => 'setting_distro',
			);
			if (isset($_REQUEST['locationid'])) {
				$this->qry_extra[] = array(
					'name'  => 'locationid',
					'value' => (int)$_REQUEST['locationid'],
					'table' => 'setting_location',
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
					$displayValue = (isset($_POST['setting'][$key]) ? $_POST['setting'][$key] : '');
					// Validate data first!
					$mangledValue = Validator::validate($validator, $displayValue);
					if ($mangledValue === false) {
						Message::addWarning('value-invalid', $key, $displayValue);
						continue;
					}
					// Now put into DB
					Database::exec("INSERT INTO setting_global (setting, value, displayvalue $qry_insert)
						VALUES (:key, :value, :displayvalue $qry_values)
						ON DUPLICATE KEY UPDATE value = :value, displayvalue = :displayvalue",
						$this->qry_extra + array(
							'key'      => $key,
							'value'    => $mangledValue,
							'displayvalue' => $displayValue
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
		$res = Database::simpleQuery('SELECT cat_setting.catid, setting.setting, setting.defaultvalue, setting.permissions, setting.validator, tbl.displayvalue
			FROM setting
			INNER JOIN cat_setting USING (catid)
			LEFT JOIN setting_global AS tbl USING (setting)
			ORDER BY cat_setting.sortval ASC, setting.setting ASC');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['description'] = Util::markup(Dictionary::translateFile('config-variables', $row['setting']));
			if (is_null($row['displayvalue'])) $row['displayvalue'] = $row['defaultvalue'];
			$row['item'] = $this->makeInput($row['validator'], $row['setting'], $row['displayvalue']);
			$settings[$row['catid']]['settings'][] = $row;
			if (!isset($settings[$row['catid']]['category_id'])) {
				$settings[$row['catid']]['category_name'] = Dictionary::translateFile('config-variable-categories', 'cat_' . $row['catid']);
				$settings[$row['catid']]['category_id'] = $row['catid'];
			}
		}
		Render::addTemplate('_page', array(
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
		// Password field guessing
		if (stripos($validator, 'password') !== false) {
			$type = Property::getPasswordFieldType();
		} else {
			$type = 'text';
		}
		// Fallback: single line input
		return '<input type="' . $type . '" name="setting[' . $setting . ']" class="form-control" size="30" value="' . $current . '">';
	}

}
