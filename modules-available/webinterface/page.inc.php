<?php

class Page_WebInterface extends Page
{

	const PROP_REDIRECT = 'webinterface.https-redirect';
	const PROP_TYPE = 'webinterface.https-type';

	protected function doPreprocess()
	{
		User::load();
		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}
		switch (Request::post('action')) {
			case 'https':
				$this->actionConfigureHttps();
				break;
			case 'password':
				$this->actionShowHidePassword();
				break;
		}
	}

	private function actionConfigureHttps()
	{
		$task = false;
		$off = '';
		switch (Request::post('mode')) {
			case 'off':
				$task = $this->setHttpsOff();
				$off = '&hsts=off';
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
		if (isset($task['id'])) {
			Session::set('https-id', $task['id']);
			Util::redirect('?do=WebInterface&show=httpsupdate' . $off);
		}
		Util::redirect('?do=WebInterface');
	}

	private function actionShowHidePassword()
	{
		Property::setPasswordFieldType(Request::post('mode') === 'show' ? 'text' : 'password');
		Util::redirect('?do=WebInterface');
	}

	protected function doRender()
	{
		//
		// HTTPS
		//
		if (Request::get('show') === 'httpsupdate') {
			Render::addTemplate('httpd-restart', array('taskid' => Session::get('https-id')));
		}
		$type = Property::get(self::PROP_TYPE);
		$force = Property::get(self::PROP_REDIRECT) === 'True';
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		$exists = file_exists('/etc/lighttpd/server.pem');
		$data = array(
			'httpsUsed' => $https,
			'redirect_checked' => ($force ? 'checked' : '')
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
		Render::addTemplate('https', $data);
		//
		// Password fields
		//
		$data = array();
		if (Property::getPasswordFieldType() === 'text')
			$data['selected_show'] = 'checked';
		else
			$data['selected_hide'] = 'checked';
		Render::addTemplate('passwords', $data);
	}

	private function setHttpsOff()
	{
		Property::set(self::PROP_TYPE, 'off');
		Header('Strict-Transport-Security: max-age=0', true);
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

