<?php

class Page_ServerSetup extends Page
{
	private $taskStatus;
	private $currentAddress;
	
	protected function doPreprocess()
	{
		User::load();
		
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}
		
		$this->currentAddress = Property::getServerIp();
		$newAddress = Request::post('ip', 'none');
		
		$this->taskStatus = Taskmanager::submit('LocalAddressesList', array());
		
		if ($this->taskStatus === false) {
			Util::redirect('?do=Main');
		}
		
		if ($this->taskStatus['statusCode'] === TASK_WAITING) { // TODO: Async if just displaying
			$this->taskStatus = Taskmanager::waitComplete($this->taskStatus['id']);
		}
		
		$sortIp = array();
		foreach (array_keys($this->taskStatus['data']['addresses']) as $key) {
			$item =& $this->taskStatus['data']['addresses'][$key];
			if (!isset($item['ip'])
					|| !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $item['ip'])
					|| substr($item['ip'], 0, 4) === '127.') {
				unset($this->taskStatus['data']['addresses'][$key]);
				continue;
			}
			if ($this->currentAddress === $item['ip']) {
				$item['default'] = true;
			}
			$sortIp[] = $item['ip'];
		}
		unset($item);
		array_multisort($sortIp, SORT_STRING, $this->taskStatus['data']['addresses']);

		if ($newAddress !== 'none') {
			// New address is to be set - check if it is valid
			$valid = false;
			foreach ($this->taskStatus['data']['addresses'] as $item) {
				if ($item['ip'] !== $newAddress) continue;
				$valid = true;
				break;
			}
			if ($valid) {
				Property::setServerIp($newAddress);
				Trigger::ipxe();
			} else {
				Message::addError('invalid-ip', $newAddress);
			}
			Util::redirect();
		}
		
	}
	
	protected function doRender()
	{
		Render::addTemplate('serversetup/ipaddress', array(
			'ips' => $this->taskStatus['data']['addresses'],
			'token' => Session::get('token')
		));
		Render::addTemplate('serversetup/ipxe', array(
			'token' => Session::get('token'),
			'taskid' => Property::getIPxeTaskId()
		));
	}
}