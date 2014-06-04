<?php

class Page_VmStore extends Page
{
	private $mountTask = false;

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
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
			Render::addTemplate('vmstore/mount', array(
				'task' => $this->mountTask['id']
			));
			return;
		}
		$vmstore = Property::getVmStoreConfig();
		if (isset($vmstore['storetype'])) {
			$vmstore['pre-' . $vmstore['storetype']] = 'checked';
		}
		$vmstore['token'] = Session::get('token');
		Render::addTemplate('page-vmstore', $vmstore);
	}
	
	private function setStore()
	{
		foreach (array('storetype', 'nfsaddr', 'cifsaddr', 'cifsuser', 'cifspasswd', 'cifsuserro', 'cifspasswdro') as $key) {
			$vmstore[$key] = trim(Request::post($key, ''));
		}
		$storetype = $vmstore['storetype'];
		if (!in_array($storetype, array('internal', 'nfs', 'cifs'))) {
			Message::addError('value-invalid', 'type', $storetype);
			Util::redirect('?do=VmStore');
		}
		// Validate syntax of nfs/cifs
		if ($storetype === 'nfs' && !preg_match('#^\S+:\S+$#is', $vmstore['nfsaddr'])) {
			Message::addError('value-invalid', 'nfsaddr', $vmstore['nfsaddr']);
			Util::redirect('?do=VmStore');
		}
		$vmstore['cifsaddr'] = str_replace('\\', '/', $vmstore['cifsaddr']);
		if ($storetype === 'cifs' && !preg_match('#^//\S+/.+$#is', $vmstore['cifsaddr'])) {
			Message::addError('value-invalid', 'nfsaddr', $vmstore['nfsaddr']);
			Util::redirect('?do=VmStore');
		}
		if ($storetype === 'nfs') $addr = $vmstore['nfsaddr'];
		if ($storetype === 'cifs') $addr = $vmstore['nfsaddr'];
		if ($storetype === 'internal') $addr = 'none';
		Property::setVmStoreConfig($vmstore);
		$this->mountTask = Trigger::mount();
	}

}