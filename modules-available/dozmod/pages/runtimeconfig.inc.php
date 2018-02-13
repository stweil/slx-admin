<?php

class SubPage
{

	public static function doPreprocess()
	{
		/* execute actions */
		$action = Request::post('action', false, 'string');

		if ($action === 'runtime') {
			User::assertPermission("runtimeconfig.save");
			self::runtimeHandler();
		}
	}

	private static function runtimeHandler()
	{
		// Check action
		$do = Request::post('button');
		if ($do === 'save') {
			$data = [];
			$data['defaultLecturePermissions'] = Request::post('defaultLecturePermissions', NULL, "array");
			$data['defaultImagePermissions'] = Request::post('defaultImagePermissions', NULL, "array");

			$params = [
				'int' => [
					'maxImageValidityDays' => array('min' => 7, 'max' => 9999),
					'maxLectureValidityDays' => array('min' => 7, 'max' => 9999),
					'maxLocationsPerLecture' => array('min' => 0, 'max' => 999),
					'maxTransfers' => array('min' => 1, 'max' => 10),
				],
				'bool' => [
					'allowLoginByDefault' => array('default' => true)
				],
			];
			foreach ($params as $type => $list) {
				foreach ($list as $field => $limits) {
					$default = isset($limits['default']) ? $limits['default'] : false;
					$value = Request::post($field, $default);
					settype($value, $type);
					if (isset($limits['min']) && $value < $limits['min']) {
						$value = $limits['min'];
					}
					if (isset($limits['max']) && $value > $limits['max']) {
						$value = $limits['max'];
					}
					$data[$field] = $value;
				}
			}

			/* ensure types */
			settype($data['defaultLecturePermissions']['edit'], 'boolean');
			settype($data['defaultLecturePermissions']['admin'], 'boolean');
			settype($data['defaultImagePermissions']['edit'], 'boolean');
			settype($data['defaultImagePermissions']['admin'], 'boolean');
			settype($data['defaultImagePermissions']['link'], 'boolean');
			settype($data['defaultImagePermissions']['download'], 'boolean');

			$data = json_encode($data);
			Database::exec('INSERT INTO sat.configuration (parameter, value)'
				. ' VALUES (:param, :value)'
				. ' ON DUPLICATE KEY UPDATE value = VALUES(value)', array(
				'param' => 'runtimelimits',
				'value' => $data
			));
			Message::addSuccess('runtimelimits-config-saved');
		}
		Util::redirect('?do=DozMod&section=runtimeconfig');
	}

	public static function doRender()
	{
		// Runtime config
		$runtimeConf = Database::queryFirst('SELECT value FROM sat.configuration WHERE parameter = :param', array('param' => 'runtimelimits'));
		if ($runtimeConf !== false) {
			$runtimeConf = json_decode($runtimeConf['value'], true);

			/* convert some value to corresponding "selected" texts */
			if ($runtimeConf['defaultLecturePermissions']['edit']) {
				$runtimeConf['defaultLecturePermissions']['edit'] = 'checked';
			}
			if ($runtimeConf['defaultLecturePermissions']['admin']) {
				$runtimeConf['defaultLecturePermissions']['admin'] = 'checked';
			}
			if ($runtimeConf['defaultImagePermissions']['edit']) {
				$runtimeConf['defaultImagePermissions']['edit'] = 'checked';
			}
			if ($runtimeConf['defaultImagePermissions']['admin']) {
				$runtimeConf['defaultImagePermissions']['admin'] = 'checked';
			}
			if ($runtimeConf['defaultImagePermissions']['link']) {
				$runtimeConf['defaultImagePermissions']['link'] = 'checked';
			}
			if ($runtimeConf['defaultImagePermissions']['download']) {
				$runtimeConf['defaultImagePermissions']['download'] = 'checked';
			}

			if ($runtimeConf['allowLoginByDefault']) {
				$runtimeConf['allowLoginByDefault'] = 'checked';
			}
		}
		$runtimeConf['allowedSave'] = User::hasPermission("runtimeconfig.save");
		Render::addTemplate('runtimeconfig', $runtimeConf);
	}

	public static function doAjax()
	{

	}

}
