<?php

class Page_WebInterface extends Page
{

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
		switch (Request::post('mode')) {
			case 'off':
				$task = $this->setHttpsOff();
				break;
			case 'random':
				$task = $this->setHttpsRandomCert();
				break;
			case 'custom':
				$task = $this->setHttpsCustomCert();
				break;
		}
		if (isset($task['id'])) {
			Session::set('https-id', $task['id']);
			Util::redirect('?do=WebInterface&show=httpsupdate');
		}
	}
	
	private function actionShowHidePassword()
	{
		Property::setPasswordFieldType(Request::post('mode') === 'show' ? 'text' : 'password');
		Util::redirect('?do=WebInterface');
	}

	protected function doRender()
	{
		Render::setTitle(Dictionary::translate('lang_titleWebinterface'));
		if (Request::get('show') === 'httpsupdate') {
			Render::addTemplate('httpd-restart', array('taskid' => Session::get('https-id')));
		}
		Render::addTemplate('https', array('httpsEnabled' => file_exists('/etc/lighttpd/server.pem')));
		$data = array();
		if (Property::getPasswordFieldType() === 'text')
			$data['selected_show'] = 'checked';
		else
			$data['selected_hide'] = 'checked';
		Render::addTemplate('passwords', $data);
	}

	private function setHttpsOff()
	{
		return Taskmanager::submit('LighttpdHttps', array());
	}

	private function setHttpsRandomCert()
	{
		return Taskmanager::submit('LighttpdHttps', array(
				'proxyip' => Property::getServerIp()
		));
	}

	private function setHttpsCustomCert()
	{
		return Taskmanager::submit('LighttpdHttps', array(
				'importcert' => Request::post('certificate', 'bla'),
				'importkey' => Request::post('privatekey', 'bla'),
				'importchain' => Request::post('cachain', '')
		));
	}

}
