<?php

class Page_BaseConfig extends Page
{
	private $qry_extra = array();
	private $categories;

	/**
	 * @var bool|string in case we're in module mode, set to the id of the module
	 */
	private $targetModule = false;

	protected function doPreprocess()
	{
		User::load();
		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		// Determine if we're setting global or module specific
		$this->getModuleSpecific();

		$newValues = Request::post('setting');
		if (is_array($newValues)) {
			if ($this->targetModule === 'locations') {
				User::assertPermission('edit', $this->qry_extra['field_value']);
			} else {
				User::assertPermission('edit', 0);
			}
			// Build variables for specific sub-settings
			if ($this->targetModule === false || empty($this->qry_extra['field'])) {
				// Global, or Module specific, but module doesn't have an extra field
				$qry_insert = '';
				$qry_values = '';
				$qry_update = '';
				$params = array();
				$delExtra = '';
			} else {
				// Module with extra field
				$qry_insert = ', ' . $this->qry_extra['field'];
				$qry_values = ', :field_value';
				$qry_update = '';
				$params = array('field_value' => $this->qry_extra['field_value']);
				$delExtra = " AND {$this->qry_extra['field']} = :field_value ";
				$delParams = array('field_value' => $this->qry_extra['field_value']);
				// Not editing global settings
				if ($this->getCurrentModuleName() === false) {
					Message::addError('main.value-invalid', $this->qry_extra['field'], $this->qry_extra['field_value']);
					Util::redirect('?do=BaseConfig');
				}
			}
			// Honor override checkbox
			$override = Request::post('override', array());
			// Load all existing config options to validate input
			$vars = BaseConfigUtil::getVariables();
			// First, handle shadowing so we don't create warnings for empty fields
			BaseConfigUtil::markShadowedVars($vars, $newValues);
			// Validate input
			foreach ($vars as $key => $var) {
				if (isset($var['shadowed']))
					continue;
				if ($this->targetModule !== false) {
					// Module mode
					if (is_array($override) && (!isset($override[$key]) || $override[$key] !== 'on')) {
						// override not set - delete
						$delParams['key'] = $key;
						Database::exec("DELETE FROM {$this->qry_extra['table']} WHERE setting = :key $delExtra", $delParams);
						continue;
					}
				}
				$validator = $var['validator'];
				$displayValue = (isset($newValues[$key]) ? $newValues[$key] : '');
				// Validate data first!
				$mangledValue = Validator::validate($validator, $displayValue);
				if ($mangledValue === false) {
					Message::addWarning('main.value-invalid', $key, $displayValue);
					continue;
				}
				// Now put into DB
				Database::exec("INSERT INTO {$this->qry_extra['table']} (setting, value, displayvalue $qry_insert)"
					. " VALUES (:key, :value, :displayvalue $qry_values)"
					. " ON DUPLICATE KEY UPDATE value = :value, displayvalue = :displayvalue $qry_update",
					array(
						'key'      => $key,
						'value'    => $mangledValue,
						'displayvalue' => $displayValue
					) + $params
				);
			}
			Message::addSuccess('settings-updated');
			if ($this->targetModule === false) {
				Util::redirect('?do=BaseConfig');
			} elseif (empty($this->qry_extra['field'])) {
				Util::redirect('?do=BaseConfig&module=' . $this->targetModule);
			} else {
				Util::redirect('?do=BaseConfig&module=' . $this->targetModule . '&' . $this->qry_extra['field'] . '=' . $this->qry_extra['field_value']);
			}
		}
		// Load categories so we can define them as sub menu items
		$this->categories = BaseConfigUtil::getCategories();
		asort($this->categories, SORT_DESC);
		foreach ($this->categories as $catid => $val) {
			Dashboard::addSubmenu(
				'#category_' . $catid,
				Dictionary::translateFileModule($this->categories[$catid]['module'], 'config-variable-categories', $catid, true)
			);
		}
	}

	protected function doRender()
	{
		// Check if valid submodule mode, store name if any
		if ($this->targetModule !== false) {
			$this->qry_extra['subheading'] = $this->getCurrentModuleName();
			if ($this->qry_extra['subheading'] === false) {
				Message::addError('main.value-invalid', $this->qry_extra['field'], $this->qry_extra['field_value']);
				Util::redirect('?do=BaseConfig');
			}
		}
		if ($this->targetModule === 'locations') {
			User::assertPermission('view', $this->qry_extra['field_value']);
			$editForbidden = !User::hasPermission('edit', $this->qry_extra['field_value']);
		} else {
			User::assertPermission('view', 0);
			$editForbidden = !User::hasPermission('edit', 0);
		}
		// Get stuff that's set in DB already
		if ($this->targetModule !== false && isset($this->qry_extra['field'])) {
			$fields = '';
			$where = " WHERE {$this->qry_extra['field']} = :field_value";
			$params = array('field_value' => $this->qry_extra['field_value']);
		} else {
			$fields = '';
			$where = '';
			$params = array();
		}
		// List config options
		$settings = array();
		$vars = BaseConfigUtil::getVariables();
		// Remember missing variables
		$missing = $vars;
		// Populate structure with existing config from db
		$this->fillSettings($vars, $settings, $missing, $this->qry_extra['table'], $fields, $where, $params, false);
		if (isset($this->qry_extra['getfallback']) && !empty($missing)) {
			$method = explode('::', $this->qry_extra['getfallback']);
			$fieldValue = $this->qry_extra['field_value'];
			$tries = 0;
			while (++$tries < 100 && !empty($missing)) {
				$ret = call_user_func($method, $fieldValue);
				if ($ret === false)
					break;
				$fieldValue = $ret['value'];
				$params = array('field_value' => $fieldValue);
				$this->fillSettings($vars, $settings, $missing, $this->qry_extra['table'], $fields, $where, $params, $ret['display']);
			}
		}
		if ($this->targetModule !== false && !empty($missing)) {
			$this->fillSettings($vars, $settings, $missing, 'setting_global', '', '', array(), 'Global');
		}
		// Add entries that weren't in the db (global), setup override checkbox (module specific)
		foreach ($vars as $key => $var) {
			if ($this->targetModule !== false && !isset($settings[$var['catid']]['settings'][$key])) {
				// Module specific - value is not set in DB
				$settings[$var['catid']]['settings'][$key] = $var + array(
					'setting' => $key
				);
			}
			if (!isset($settings[$var['catid']]['settings'][$key]['displayvalue'])) {
				$settings[$var['catid']]['settings'][$key]['displayvalue'] = $var['defaultvalue'];
			}
			if (!isset($settings[$var['catid']]['settings'][$key]['shadows'])) {
				$settings[$var['catid']]['settings'][$key]['shadows'] = isset($var['shadows']) ? $var['shadows'] : null;
			}
			$settings[$var['catid']]['settings'][$key] += array(
				'item' => $this->makeInput(
					$var['validator'],
					$key,
					$settings[$var['catid']]['settings'][$key]['displayvalue'],
					$settings[$var['catid']]['settings'][$key]['shadows'],
					$editForbidden
				),
				'description' => Util::markup(Dictionary::translateFileModule($var['module'], 'config-variables', $key)),
				'setting' => $key,
			);
		}
		//die();


		// Sort categories
		$sortvals = array();
		foreach ($settings as $catid => &$setting) {
			$sortvals[] = isset($this->categories[$catid]) ? (int)$this->categories[$catid]['sortpos'] : 99999;
			$setting['category_id'] = $catid;
			$setting['category_name'] = Dictionary::translateFileModule($this->categories[$catid]['module'], 'config-variable-categories', $catid);
			if ($setting['category_name'] === false) {
				$setting['category_name'] = $catid;
			}
			ksort($setting['settings']);
			$setting['settings'] = array_values($setting['settings']);
		}
		unset($setting);
		array_multisort($sortvals, SORT_ASC, SORT_NUMERIC, $settings);
		Render::addTemplate('_page', array(
			'override' => $this->targetModule !== false,
			'categories'  => array_values($settings),
			'target_module' => $this->targetModule,
			'edit_disabled' => $editForbidden ? 'disabled' : '',
		) + $this->qry_extra);
	}

	private function fillSettings($vars, &$settings, &$missing, $table, $fields, $where, $params, $sourceName)
	{
		$res = Database::simpleQuery("SELECT setting, value, displayvalue $fields FROM $table "
			. " {$where} ORDER BY setting ASC", $params);
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($missing[$row['setting']]))
				continue;
			if (!isset($vars[$row['setting']]) || !is_array($vars[$row['setting']])) {
				$unknown[] = $row['setting'];
				continue;
			}
			unset($missing[$row['setting']]);
			if ($sourceName !== false) {
				$row['defaultvalue'] = '';
				$row['defaultsource'] = $sourceName;
			} elseif ($this->targetModule !== false) {
				$row['checked'] = 'checked';
			}
			$row += $vars[$row['setting']];
			if (!isset($row['catid'])) {
				$row['catid'] = 'unknown';
			}
			$settings[$row['catid']]['settings'][$row['setting']] = $row;
		}
	}

	private function getCurrentModuleName()
	{
		if (isset($this->qry_extra['tostring'])) {
			$method = explode('::', $this->qry_extra['tostring']);
			return call_user_func($method, $this->qry_extra['field_value']);
		}
		if (isset($this->qry_extra['field'])) {
			return $this->targetModule . ' // ' . $this->qry_extra['field'] . '=' . $this->qry_extra['field_value'];
		}
		return $this->targetModule;
	}

	private function getModuleSpecific()
	{
		$module = Request::any('module', '', 'string');
		if ($module === '') {
			$this->qry_extra = array(
				'table' => 'setting_global',
			);
			return;
		}
		//\\//\\//\\
		if (!Module::isAvailable($module)) {
			Message::addError('main.no-such-module', $module);
			Util::redirect('?do=baseconfig');
		}
		$file = 'modules/' . $module . '/baseconfig/hook.json';
		if (!file_exists($file)) {
			Message::addError('no-module-hook', $module);
			Util::redirect('?do=baseconfig');
		}
		$hook = json_decode(file_get_contents($file), true);
		if (empty($hook['table'])) {
			Message::addError('invalid-hook', $module);
			Util::redirect('?do=baseconfig');
		}
		if (isset($hook['field'])) {
			$hook['field_value'] = Request::any($hook['field'], '0', 'string');
		}
		$this->targetModule = $module;
		$this->qry_extra = $hook;
	}
	
	/**
	 * Create html snippet for setting, based on given validator
	 * @param string $validator
	 * @return boolean
	 */
	private function makeInput($validator, $setting, $current, $shadows, $disabled)
	{
		/* for the html snippet we need: */
		$args = array('class' => 'form-control', 'name' => "setting[$setting]", 'id' => $setting);
		if (!empty($shadows)) {
			$args['data-shadows'] = json_encode($shadows);
		}
		if ($disabled) {
			$args['disabled'] = true;
		}
		$inner = "";
		/* -- */

		$parts = explode(':', $validator, 2);

		if ($parts[0] === 'list') {

			$items = explode('|', $parts[1]);
			foreach ($items as $item) {
				if ($item === $current) {
					$inner .= "<option selected=\"selected\" value=\"$item\"> $item  </option>";
				} else {
					$inner .= "<option value=\"$item\"> $item </option>";
				}
			}

			$tag = 'select';
			unset($args['type']);
			$current = '';

		} elseif ($parts[0] == 'multilist') {

			$items = explode('|', $parts[1]);
			$args['multiple'] = 'multiple';
			$args['class'] 	 .= " multilist";
			$args['name']    .= '[]';

			$selected = explode(' ', $current);

			foreach ($items as $item) {
				if (in_array($item, $selected)) {
					$inner .= "<option selected=\"selected\" value=\"$item\"> $item  </option>";
				} else {
					$inner .= "<option value=\"$item\"> $item </option>";
				}
			}
			$tag = 'select';
			unset($args['type']);
			$current = '';
		} else {
			// Everything else is a text input for now
			$tag = 'input';
			$args['value'] = $current;
			$args['type'] = 'text';
			/* Password field guessing */
			if (stripos($validator, 'password') !== false) {
				$args['type'] = Property::getPasswordFieldType();
			}
		}

		/* multiinput: enter multiple free-form strings*/
		if ($validator === 'multiinput') {
			$args['class'] .= " multiinput";
		}

		$output = "<$tag ";
		foreach ($args as $key => $val) {
			if ($val === true) {
				$output .= $key . ' ';
			}
			$output .= "$key=\"" . htmlspecialchars($val) . '" ';
		}
		if (empty($inner)) {
			$output .= '>';
		} else {
			$output .= '>' . $inner . "</$tag>";
		}

		return $output;
	}

}
