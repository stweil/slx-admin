<?php

class Page_VmStore extends Page
{
	private $mountTask = false;

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}
		
		$action = Request::post('action');
		
		if ($action === 'setstore') {
			$this->setStore();
		}
	}
	
	protected function doRender()
	{
		$action = Request::post('action');
		if ($action === 'setstore' && !Taskmanager::isFailed($this->mountTask)) {
			Render::addTemplate('mount', array(
				'task' => $this->mountTask['id']
			));
			return;
		}
		$vmstore = Property::getVmStoreConfig();
		if (isset($vmstore['storetype'])) {
			$vmstore['pre-' . $vmstore['storetype']] = 'checked';
		}
		$vmstore['internalAllowed'] = User::hasPermission("choose.internal");
		$vmstore['nfsAllowed'] = User::hasPermission("choose.nfs");
		$vmstore['cifsAllowed'] = User::hasPermission("choose.cifs");
		$vmstore['saveAllowed'] = $vmstore['internalAllowed'] || $vmstore['nfsAllowed'] || $vmstore['cifsAllowed'];
		Render::addTemplate('page-vmstore', $vmstore);
	}
	
	private function setStore()
	{
		$vmstore = array();
		foreach (array('storetype', 'nfsaddr', 'cifsaddr', 'cifsuser', 'cifspasswd', 'cifsuserro', 'cifspasswdro') as $key) {
			$vmstore[$key] = trim(Request::post($key, '', 'string'));
		}
		$storetype = $vmstore['storetype'];
		if (!in_array($storetype, array('internal', 'nfs', 'cifs'))) {
			Message::addError('main.value-invalid', 'type', $storetype);
			Util::redirect('?do=VmStore');
		}
		if (($storetype === 'internal' && !User::hasPermission("choose.internal")) ||
			 ($storetype === 'nfs' && !User::hasPermission("choose.nfs")) ||
			 ($storetype === 'cifs' && !User::hasPermission("choose.cifs"))) {
			Message::addError('main.no-permission');
			Util::redirect('?do=VmStore');
		}
		// Validate syntax of nfs/cifs
		if ($storetype === 'nfs' && !preg_match('#^\S+:\S+$#is', $vmstore['nfsaddr'])) {
			Message::addError('main.value-invalid', 'nfsaddr', $vmstore['nfsaddr']);
			Util::redirect('?do=VmStore');
		}
		$vmstore['cifsaddr'] = str_replace('\\', '/', $vmstore['cifsaddr']);
		if ($storetype === 'cifs' && !preg_match('#^//\S+/.+$#is', $vmstore['cifsaddr'])) {
			Message::addError('main.value-invalid', 'nfsaddr', $vmstore['nfsaddr']);
			Util::redirect('?do=VmStore');
		}
		$this->mountTask = Trigger::mount($vmstore);
		if ($this->mountTask !== false) {
			TaskmanagerCallback::addCallback($this->mountTask, 'manualMount', $vmstore);
		}
	}

}