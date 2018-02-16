<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', false, 'string');

		if ($action === 'mail') {
			User::assertPermission("mailconfig.save");
			self::mailHandler();
		}
	}

	private static function mailHandler()
	{
		// Check action
		$do = Request::post('button');
		if ($do === 'save') {
			// Prepare array
			$data = self::cleanMailArray();
			$data = json_encode($data);
			Database::exec('INSERT INTO sat.configuration (parameter, value)'
				. ' VALUES (:param, :value)'
				. ' ON DUPLICATE KEY UPDATE value = VALUES(value)', array(
				'param' => 'mailconfig',
				'value' => $data
			));
			Message::addSuccess('mail-config-saved');
		} else {
			Message::addError('main.invalid-action', $do);
		}
		Util::redirect('?do=DozMod&section=mailconfig');
	}

	private static function cleanMailArray()
	{
		$keys = array('host', 'port', 'ssl', 'senderAddress', 'replyTo', 'username', 'password', 'serverName');
		$data = array();
		foreach ($keys as $key) {
			$data[$key] = Request::post($key, '');
			settype($data[$key], 'string');
			if (is_numeric($data[$key])) {
				settype($data[$key], 'int');
			}
		}
		return $data;
	}

	public static function doRender()
	{
		// Mail config
		$mailConf = Database::queryFirst('SELECT value FROM sat.configuration WHERE parameter = :param', array('param' => 'mailconfig'));
		if ($mailConf != null) {
			$mailConf = @json_decode($mailConf['value'], true);
			if (is_array($mailConf)) {
				$mailConf['set_' . $mailConf['ssl']] = 'selected="selected"';
			}
		}
		Permission::addGlobalTags($mailConf['perms'], null, ['mailconfig.save']);
		Render::addTemplate('mailconfig', $mailConf);
	}

	public static function doAjax()
	{
		User::assertPermission("mailconfig.save");
		$action = Request::post('action');
		if ($action === 'mail') {
			self::handleTestMail();
		}
	}

	private static function handleTestMail()
	{
		$do = Request::post('button');
		if ($do === 'test') {
			// Prepare array
			$data = self::cleanMailArray();
			Header('Content-Type: text/plain; charset=utf-8');
			$data['recipient'] = Request::post('recipient', '');
			if (!preg_match('/.+@.+\..+/', $data['recipient'])) {
				$result = 'No recipient given!';
			} else {
				$result = Download::asStringPost('http://127.0.0.1:9080/do/mailtest', $data, 10, $code);
				if ($code == 999) {
					$result .= "\nTimeout.";
				} elseif ($code != 200) {
					$result .= "\nReturn code $code";
				}
			}
			die($result);
		}
	}

}
