<?php

class Page_Https extends Page
{

	protected function doPreprocess()
	{
		User::load();
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}
		$task = false;
		switch (Request::post('mode')) {
		case 'off':
			$task = $this->setOff();
			break;
		case 'random':
			$task = $this->setRandom();
			break;
		case 'custom':
			$task = $this->setCustom();
			break;
		}
		if (isset($task['id'])) {
			Session::set('https-id', $task['id']);
			Util::redirect('?do=Https&show=update');
		}
	}

	protected function doRender()
	{
		if (Request::get('show') === 'update') {
			Render::addTemplate('https/restart', array('taskid' => Session::get('https-id')));
		}
		Render::addTemplate('https/_page');
	}
	
	private function setOff()
	{
		return Taskmanager::submit('LighttpdHttps', array());
	}
	
	private function setRandom()
	{
		return Taskmanager::submit('LighttpdHttps', array(
			'proxyip' => Property::getServerIp()
		));
	}
	
	private function setCustom()
	{
		return Taskmanager::submit('LighttpdHttps', array(
			'importcert'  => Request::post('certificate', 'bla'),
			'importkey'   => Request::post('privatekey', 'bla'),
			'importchain' => Request::post('cachain', '')
		));
	}

}
