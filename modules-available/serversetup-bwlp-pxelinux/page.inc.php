<?php

class Page_ServerSetup extends Page
{

	private $taskStatus;
	private $currentAddress;
	private $currentMenu;
	private $hasIpSet = false;

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		if (Request::any('action') === 'getimage') {
			User::assertPermission("download");
			$this->handleGetImage();
		}

		$this->currentMenu = Property::getBootMenu();

		$action = Request::post('action');

		if ($action === false) {
			$this->currentAddress = Property::getServerIp();
			$this->getLocalAddresses();
		}

		if ($action === 'ip') {
			User::assertPermission("edit.address");
			// New address is to be set
			$this->getLocalAddresses();
			$this->updateLocalAddress();
		}

		if ($action === 'ipxe') {
			User::assertPermission("edit.menu");
			// iPXE stuff changes
			$this->updatePxeMenu();
		}

		if (Request::isPost()) {
			Util::redirect('?do=serversetup');
		}

		User::assertPermission('access-page');
	}

	protected function doRender()
	{
		Render::addTemplate("heading");
		$task = Property::get('ipxe-task-id');
		if ($task !== false) {
			$task = Taskmanager::status($task);
			if (!Taskmanager::isTask($task) || Taskmanager::isFinished($task)) {
				$task = false;
			}
		}
		if ($task !== false) {
			Render::addTemplate('ipxe_update', array('taskid' => $task['id']));
		}

		Permission::addGlobalTags($perms, null, ['edit.menu', 'edit.address', 'download']);

		Render::addTemplate('ipaddress', array(
			'ips' => $this->taskStatus['data']['addresses'],
			'chooseHintClass' => $this->hasIpSet ? '' : 'alert alert-danger',
			'editAllowed' => User::hasPermission("edit.address"),
			'perms' => $perms,
		));
		$data = $this->currentMenu;
		if (!User::hasPermission('edit.menu')) {
			unset($data['masterpasswordclear']);
		}
		if (!isset($data['defaultentry'])) {
			$data['defaultentry'] = 'net';
		}
		if ($data['defaultentry'] === 'net') {
			$data['active-net'] = 'checked';
		}
		if ($data['defaultentry'] === 'hdd') {
			$data['active-hdd'] = 'checked';
		}
		if ($data['defaultentry'] === 'custom') {
			$data['active-custom'] = 'checked';
		}
		$data['perms'] = $perms;
		Render::addTemplate('ipxe', $data);
	}

	// -----------------------------------------------------------------------------------------------

	private function getLocalAddresses()
	{
		$this->taskStatus = Taskmanager::submit('LocalAddressesList', array());

		if ($this->taskStatus === false) {
			$this->taskStatus['data']['addresses'] = false;
			return false;
		}

		if (!Taskmanager::isFinished($this->taskStatus)) { // TODO: Async if just displaying
			$this->taskStatus = Taskmanager::waitComplete($this->taskStatus['id'], 4000);
		}

		if (Taskmanager::isFailed($this->taskStatus) || !isset($this->taskStatus['data']['addresses'])) {
			$this->taskStatus['data']['addresses'] = false;
			return false;
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
				$this->hasIpSet = true;
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
			Util::redirect('?do=ServerSetup');
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
		Trigger::ipxe();
		Util::redirect('?do=ServerSetup');
	}

	private function handleGetImage()
	{
		$file = "/opt/openslx/ipxe/openslx-bootstick.raw";
		if (!is_readable($file)) {
			Message::addError('image-not-found');
			return;
		}
		Header('Content-Type: application/octet-stream');
		Header('Content-Disposition: attachment; filename="openslx-bootstick-' . Property::getServerIp() . '-raw.img"');
		readfile($file);
		exit;
	}

}
