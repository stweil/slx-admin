<?php

class Page_WebInterface extends Page
{

	const PROP_REDIRECT = 'webinterface.https-redirect';
	const PROP_TYPE = 'webinterface.https-type';
	const PROP_HSTS = 'webinterface.https-hsts';

	protected function doPreprocess()
	{
		User::load();
		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}
		switch (Request::post('action')) {
			case 'https':
				User::assertPermission("edit.https");
				$this->actionConfigureHttps();
				break;
			case 'password':
				User::assertPermission("edit.password");
				$this->actionShowHidePassword();
				break;
			case 'customization':
				User::assertPermission("edit.design");
				$this->actionCustomization();
				break;
		}
	}

	private function actionConfigureHttps()
	{
		$mode = Request::post('mode');
		switch ($mode) {
			case 'off':
				$task = $this->setHttpsOff();
				break;
			case 'random':
				$task = $this->setHttpsRandomCert();
				break;
			case 'custom':
				$task = $this->setHttpsCustomCert();
				break;
			default:
				$task = $this->setRedirectMode();
				break;
		}
		if ($mode !== 'off') {
			Property::set(self::PROP_HSTS, Request::post('usehsts', false, 'string') === 'on' ? 'True' : 'False');
		}
		if (isset($task['id'])) {
			Session::set('https-id', $task['id']);
			Util::redirect('?do=WebInterface&show=httpsupdate');
		}
		Util::redirect('?do=WebInterface');
	}

	private function actionShowHidePassword()
	{
		Property::setPasswordFieldType(Request::post('mode') === 'show' ? 'text' : 'password');
		Util::redirect('?do=WebInterface');
	}

	private function actionCustomization()
	{
		$prefix = Request::post('prefix', '', 'string');
		if (!empty($prefix) && !preg_match('/[\]\)\}\-_\s\&\$\!\/\+\*\^\>]$/', $prefix)) {
			$prefix .= ' ';
		}
		Property::set('page-title-prefix', $prefix);
		Property::set('logo-background', Request::post('bgcolor', '', 'string'));
		Util::redirect('?do=WebInterface');
	}

	protected function doRender()
	{
		Render::addTemplate("heading");
		//
		// HTTPS
		//
		if (Request::get('show') === 'httpsupdate') {
			Render::addTemplate('httpd-restart', array('taskid' => Session::get('https-id')));
		}
		$type = Property::get(self::PROP_TYPE);
		$force = Property::get(self::PROP_REDIRECT) === 'True';
		$hsts = Property::get(self::PROP_HSTS) === 'True';
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		$exists = file_exists('/etc/lighttpd/server.pem');
		$data = array(
			'httpsUsed' => $https,
			'redirect_checked' => ($force ? 'checked' : ''),
			'hsts_checked' => ($hsts ? 'checked' : '')
		);
		// Type should be 'off', 'generated', 'supplied'
		if ($type === 'off') {
			if ($exists) {
				// HTTPS is set to off, but a certificate exists
				if ($https) {
					// User is using https, just warn to prevent lockout
					Message::addWarning('https-want-off-is-used');
				} else {
					// User is not using https, try to delete stray certificate
					$this->setHttpsOff();
				}
			} elseif ($https) {
				// Set to off, no cert found, but still using HTTPS apparently
				// Admin might have modified web server config in another way
				Message::addWarning('https-used-without-cert');
			}
		} elseif ($type === 'generated' || $type === 'supplied') {
			$data['httpsEnabled'] = true;
			if ($force && !$https) {
				Message::addWarning('https-want-redirect-is-plain');
			}
			if (!$exists) {
				Message::addWarning('https-on-cert-missing');
			}
		} else {
			// Unknown config - maybe upgraded old install that doesn't keep track
			if ($exists || $https) {
				$type = 'unknown'; // Legacy fallback
			} else {
				$type = 'off';
			}
		}
		$data[$type . 'Selected'] = true;
		Permission::addGlobalTags($data['perms'], null, ['edit.https']);
		Render::addTemplate('https', $data);
		//
		// Password fields
		//
		$data = array();
		if (Property::getPasswordFieldType() === 'text')
			$data['selected_show'] = 'checked';
		else
			$data['selected_hide'] = 'checked';
		Permission::addGlobalTags($data['perms'], null, ['edit.password']);
		Render::addTemplate('passwords', $data);
		//
		// Colors/Prefix
		//
		$data = array('prefix' => Property::get('page-title-prefix'));
		$data['colors'] = array_map(function ($i) { return array('color' => $i ? '#' . $i : '', 'text' => Render::readableColor($i)); },
			array('', 'f00', '0f0', '00f', 'ff0', 'f0f', '0ff', 'fff', '000', 'f90', '09f', '90f', 'f09', '9f0'));
		$color = Property::get('logo-background');
		foreach ($data['colors'] as &$c) {
			if ($c['color'] === $color) {
				$c['selected'] = 'selected';
				$color = false;
				break;
			}
		}
		unset($c);
		if ($color) {
			$data['colors'][] = array('color' => $color, 'selected' => 'selected');
		}
		Permission::addGlobalTags($data['perms'], null, ['edit.design']);
		Render::addTemplate('customization', $data);
	}

	private function setHttpsOff()
	{
		Property::set(self::PROP_TYPE, 'off');
		Property::set(self::PROP_HSTS, 'off');
		Header('Strict-Transport-Security: max-age=0', true);
		Session::deleteCookie();
		return Taskmanager::submit('LighttpdHttps', array());
	}

	private function setHttpsRandomCert()
	{
		$force = Request::post('httpsredirect', false, 'string') === 'on';
		Property::set(self::PROP_TYPE, 'generated');
		Property::set(self::PROP_REDIRECT, $force ? 'True' : 'False');
		return Taskmanager::submit('LighttpdHttps', array(
				'proxyip' => Property::getServerIp(),
				'redirect' => $force,
		));
	}

	private function setHttpsCustomCert()
	{
		$force = Request::post('httpsredirect', false, 'string') === 'on';
		Property::set(self::PROP_TYPE, 'supplied');
		Property::set(self::PROP_REDIRECT, $force ? 'True' : 'False');
		return Taskmanager::submit('LighttpdHttps', array(
				'importcert' => Request::post('certificate', 'bla'),
				'importkey' => Request::post('privatekey', 'bla'),
				'importchain' => Request::post('cachain', ''),
				'redirect' => $force,
		));
	}

	private function setRedirectMode()
	{
		$force = Request::post('httpsredirect', false, 'string') === 'on';
		Property::set(self::PROP_REDIRECT, $force ? 'True' : 'False');
		if (Property::get(self::PROP_TYPE) === 'off') {
			// Don't bother running the task if https isn't enabled - just
			// update the state in DB
			return false;
		}
		return Taskmanager::submit('LighttpdHttps', array(
			'redirectOnly' => true,
			'redirect' => $force,
		));
	}

}

