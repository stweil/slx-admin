<?php

class Page_ServerSetup extends Page
{

	private $mountIpxeTask;
	private $taskStatus;
	private $currentAddress;
	private $currentMenu;

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$this->currentMenu = Property::getBootMenu();

		if(Request::get('download') !== false){
			$this->downloadIpxe(Request::get('download'));
		}

		if(Request::get('defaultIpxe') !== false){
			$this->defaultIpxe(Request::get('defaultIpxe'));
		}

		$action = Request::post('action');

		if ($action === false) {
			$this->currentAddress = Property::getServerIp();
			$this->getLocalAddresses();
		}

		if ($action === 'ip') {
			// New address is to be set
			$this->getLocalAddresses();
			$this->updateLocalAddress();
		}

		if ($action === 'ipxe') {
			// iPXE stuff changes
			$this->updatePxeMenu();
		}

		if($action === 'save-script') {
			// Save new iPXE script
			$this->updateIpxeScript();
		}

		if($action === 'default-script') {
			// Restore iPXE script to default
			$this->defaultIpxe();
		}
	}

	protected function doRender()
	{
		Render::setTitle(Dictionary::translate('lang_serverConfiguration'));

		$taskid = Request::any('taskid');
		if ($taskid !== false && Taskmanager::isTask($taskid)) {
			Render::addTemplate('ipxe_update', array('taskid' => $taskid));
		}

		if (Request::get('advanced', 'false', 'string') === 'false') {
			Render::addTemplate('ipxe-smp');
		} else {
			Render::addTemplate('ipaddress', array(
				'ips' => $this->taskStatus['data']['addresses']
			));
			$data = $this->currentMenu;
			if (!isset($data['defaultentry']))
				$data['defaultentry'] = 'net';
			if ($data['defaultentry'] === 'net')
				$data['active-net'] = 'checked';
			if ($data['defaultentry'] === 'hdd')
				$data['active-hdd'] = 'checked';
			if ($data['defaultentry'] === 'custom')
				$data['active-custom'] = 'checked';
			//There is no $this->username and no pxe.embed, why do we need this?
			//Page won't load with lines below uncommented
			//$data['username'] = $this->username;
			//$data['script'] = file_get_contents("/opt/taskmanager/data/pxe.embed");
			Render::addTemplate('ipxe-adv', $data);
		}
	}

	// -----------------------------------------------------------------------------------------------

	private function getLocalAddresses()
	{
		$this->taskStatus = Taskmanager::submit('LocalAddressesList', array());

		if ($this->taskStatus === false) {
			$this->taskStatus['data']['addresses'] = false;
			return false;
		}

		if ($this->taskStatus['statusCode'] === TASK_WAITING) { // TODO: Async if just displaying
			$this->taskStatus = Taskmanager::waitComplete($this->taskStatus['id']);
		}

		$sortIp = array();
		foreach (array_keys($this->taskStatus['data']['addresses']) as $key) {
			$item = & $this->taskStatus['data']['addresses'][$key];
			if (!isset($item['ip']) || !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $item['ip']) || substr($item['ip'], 0, 4) === '127.') {
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
		return true;
	}

	private function updateLocalAddress()
	{
		$newAddress = Request::post('ip', 'none');
		$valid = false;
		foreach ($this->taskStatus['data']['addresses'] as $item) {
			if ($item['ip'] !== $newAddress)
				continue;
			$valid = true;
			break;
		}
		if ($valid) {
			Property::setServerIp($newAddress);
			global $tidIpxe;
			if (isset($tidIpxe) && $tidIpxe !== false)
				Util::redirect('?do=ServerSetup&taskid=' . $tidIpxe);
		} else {
			Message::addError('invalid-ip', $newAddress);
		}
		Util::redirect();
	}

	private function updatePxeMenu()
	{
		$timeout = Request::post('timeout', 10);
		if ($timeout === '')
			$timeout = 0;
		if (!is_numeric($timeout) || $timeout < 0) {
			Message::addError('main.value-invalid', 'timeout', $timeout);
		}
		$this->currentMenu['defaultentry'] = Request::post('defaultentry', 'net');
		$this->currentMenu['timeout'] = $timeout;
		$this->currentMenu['custom'] = Request::post('custom', '');
		$this->currentMenu['masterpasswordclear'] = Request::post('masterpassword', '');
		if (empty($this->currentMenu['masterpasswordclear']))
			$this->currentMenu['masterpassword'] = 'invalid';
		else
			$this->currentMenu['masterpassword'] = Crypto::hash6($this->currentMenu['masterpasswordclear']);
		Property::setBootMenu($this->currentMenu);
		$id = Trigger::ipxe();
		Util::redirect('?do=ServerSetup&taskid=' . $id);
	}

	private function downloadIpxe($ipxe){
		$file = '/opt/taskmanager/data/ipxe/src/bin/ipxe.' . $ipxe;
		if (file_exists($file)) {
			header('Content-Description: File Transfer');
			header('Content-Type: application/octet-stream');
			header('Content-Disposition: attachment; filename='.basename($file));
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			header('Content-Length: ' . filesize($file));
			ob_clean();
    			flush();
			readfile($file);
			exit();
		}
	}

	private function updateIpxeScript(){
		$newScript = Request::post('custom-script');
		file_put_contents("/opt/taskmanager/data/pxe.embed",$newScript);
		Util::redirect('?do=ServerSetup');
	}

	private function defaultIpxe(){
		$default = file_get_contents("/opt/taskmanager/data/pxe_default.embed");
		$default = str_replace("{{ip}}", "http://" . Property::getServerIp(), $default);
		file_put_contents("/opt/taskmanager/data/pxe.embed",$default);
		Util::redirect('?do=ServerSetup');
	}
}