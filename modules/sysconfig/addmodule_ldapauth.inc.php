<?php

/*
 * Wizard for setting up ldap integration for authentication.
 */

class LdapAuth_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		if ($this->edit !== false) {
			$data = array(
				'title' => $this->edit->title(),
				'server' => $this->edit->getData('server'),
				'searchbase' => $this->edit->getData('searchbase'),
				'binddn' => $this->edit->getData('binddn'),
				'bindpw' => $this->edit->getData('bindpw'),
				'home' => $this->edit->getData('home'),
				'ssl' => $this->edit->getData('ssl'),
				'edit' => $this->edit->id()
			);
		} else {
			$data = array(
				'title' => Request::post('title'),
				'server' => Request::post('server'),
				'searchbase' => Request::post('searchbase'),
				'binddn' => Request::post('binddn'),
				'bindpw' => Request::post('bindpw'),
				'home' => Request::post('home'),
				'ssl' => Request::post('ssl')
			);
		}
		if (preg_match('/^(.*)\:(636|389)$/', $data['server'], $out)) {
			$data['server'] = $out[1];
		}
		$data['step'] = 'LdapAuth_CheckConnection';
		Render::addDialog(Dictionary::translate('config-module', 'ldapAuth_title'), false, 'sysconfig/ldap-start', $data);
	}

}

class LdapAuth_CheckConnection extends AddModule_Base
{

	private $scanTask;
	private $server;

	protected function preprocessInternal()
	{
		$this->server = Request::post('server');
		$searchbase = Request::post('searchbase');
		$ssl = Request::post('ssl', 'off') === 'on';
		if (empty($this->server) || empty($searchbase)) {
			Message::addError('empty-field');
			AddModule_Base::setStep('LdapAuth_Start'); // Continues with LdapAuth_Start for render()
			return;
		}
		if (preg_match('/^([^\:]+)\:(\d+)$/', $this->server, $out)) {
			$ports = array($out[2]);
			$this->server = $out[1];
		} elseif ($ssl) {
			$ports = array(636, 3269);
		} else {
			$ports = array(389, 3268);
		}
		$this->scanTask = Taskmanager::submit('PortScan', array(
				'host' => $this->server,
				'ports' => $ports
		));
		if (!isset($this->scanTask['id'])) {
			AddModule_Base::setStep('LdapAuth_Start'); // Continues with LdapAuth_Start for render()
			return;
		}
	}

	protected function renderInternal()
	{
		$data = array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => $this->server,
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'ssl' => Request::post('ssl'),
			'taskid' => $this->scanTask['id']
		);
		$data['step'] = 'LdapAuth_CheckCredentials';
		Render::addDialog(Dictionary::translate('config-module', 'ldapAuth_title'), false, 'sysconfig/ldap-checkconnection', $data);
	}

}

class LdapAuth_CheckCredentials extends AddModule_Base
{

	private $taskIds;

	protected function preprocessInternal()
	{
		$server = Request::post('server');
		$port = Request::post('port');
		$searchbase = Request::post('searchbase', '');
		$binddn = Request::post('binddn');
		$bindpw = Request::post('bindpw');
		$ssl = Request::post('ssl', 'off') === 'on';
		if ($ssl && !Request::post('fingerprint')) {
			Message::addError('error-read', 'fingerprint');
			AddModule_Base::setStep('LdapAuth_Start'); // Continues with LdapAuth_Start for render()
			return;
		}
		if (empty($server) || empty($port)) {
			Message::addError('empty-field');
			AddModule_Base::setStep('LdapAuth_Start'); // Continues with LdapAuth_Start for render()
			return;
		}
		$parent = null;
		$server .= ':' . $port;
		if ($ssl) {
			$uri = "ldaps://$server/";
		} else {
			$uri = "ldap://$server/";
		}
		$ldapSearch = Taskmanager::submit('LdapSearch', array(
				'parentTask' => $parent,
				'server' => $uri,
				'searchbase' => $searchbase,
				'binddn' => $binddn,
				'bindpw' => $bindpw,
				'plainldap' => true,
		));
		if (!isset($ldapSearch['id'])) {
			AddModule_Base::setStep('LdapAuth_Start'); // Continues with LdapAuth_Start for render()
			return;
		}
		$this->taskIds = array(
			'tm-search' => $ldapSearch['id']
		);
		if (isset($selfSearch['id']))
			$this->taskIds['self-search'] = $selfSearch['id'];
	}

	protected function renderInternal()
	{
		Render::addDialog(Dictionary::translate('config-module', 'ldapAuth_title'), false, 'sysconfig/ldap-checkcredentials', array_merge($this->taskIds, array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => Request::post('server') . ':' . Request::post('port'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'ssl' => Request::post('ssl') === 'on',
			'fingerprint' => Request::post('fingerprint'),
			'step' => 'LdapAuth_Finish'
			))
		);
	}

}

class LdapAuth_Finish extends AddModule_Base
{

	private $taskIds;

	protected function preprocessInternal()
	{
		$binddn = Request::post('binddn');
		$searchbase = Request::post('searchbase');
		$title = Request::post('title');
		if (empty($title))
			$title = 'LDAP: ' . Request::post('server');
		if ($this->edit === false)
			$module = ConfigModule::getInstance('LdapAuth');
		else
			$module = $this->edit;
		$ssl = Request::post('ssl', 'off') === 'on';
		$module->setData('server', Request::post('server'));
		$module->setData('searchbase', $searchbase);
		$module->setData('binddn', $binddn);
		$module->setData('bindpw', Request::post('bindpw'));
		$module->setData('home', Request::post('home'));
		$module->setData('ssl', $ssl);
		if ($ssl) {
			$module->setData('fingerprint', Request::post('fingerprint', ''));
		} else {
			$module->setData('fingerprint', '');
		}
		if ($this->edit !== false)
			$ret = $module->update($title);
		else
			$ret = $module->insert($title);
		if (!$ret) {
			Message::addError('value-invalid', 'any', 'any');
			$tgz = false;
		} else {
			$parent = $this->stopOldInstance();
			$tgz = $module->generate($this->edit === false, $parent);
		}
		if ($tgz === false) {
			AddModule_Base::setStep('LdapAuth_Start'); // Continues with LdapAuth_Start for render()
			return;
		}
		$this->taskIds = array(
			'tm-config' => $tgz,
		);
	}
	
	private function stopOldInstance()
	{
		if ($this->edit === false)
			return NULL;
		$list = ConfigTgz::getAllForModule($this->edit->id());
		if (!is_array($list))
			return NULL;
		$parent = NULL;
		foreach ($list as $tgz) {
			if (!$tgz->isActive())
				continue;
			$task = Trigger::ldadp($tgz->id(), $parent);
			if (isset($task['id']))
				$parent = $task['id'];
		}
		return $parent;
	}

	protected function renderInternal()
	{
		Render::addDialog(Dictionary::translate('config-module', 'ldapAuth_title'), false, 'sysconfig/ldap-finish', $this->taskIds);
	}

}
