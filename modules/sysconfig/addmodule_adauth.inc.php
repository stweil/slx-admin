<?php

/*
 * Wizard for setting up active directory integration for authentication.
 */

class AdAuth_Start extends AddModule_Base
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
		$data['step'] = 'AdAuth_CheckConnection';
		Render::addDialog(Dictionary::translate('config-module', 'adAuth_title'), false, 'sysconfig/ad-start', $data);
	}

}

class AdAuth_CheckConnection extends AddModule_Base
{

	private $scanTask;

	protected function preprocessInternal()
	{
		$server = Request::post('server');
		$binddn = Request::post('binddn');
		$ssl = Request::post('ssl', 'off') === 'on';
		if (empty($server) || empty($binddn)) {
			Message::addError('empty-field');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		if (preg_match('/^([^\:]+)\:(\d+)$/', $server, $out)) {
			$ports = array($out[2]);
			$server = $out[1];
		} elseif ($ssl) {
			$ports = array(636, 3269);
		} else {
			$ports = array(389, 3268);
		}
		$this->scanTask = Taskmanager::submit('PortScan', array(
				'host' => $server,
				'ports' => $ports
		));
		if (!isset($this->scanTask['id'])) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
	}

	protected function renderInternal()
	{
		$data = array(
			'title' => Request::post('title'),
			'server' => Request::post('server'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'ssl' => Request::post('ssl'),
			'taskid' => $this->scanTask['id']
		);
		$data['step'] = 'AdAuth_CheckCredentials';
		Render::addDialog(Dictionary::translate('config-module', 'adAuth_title'), false, 'sysconfig/ad-checkconnection', $data);
	}

}

class AdAuth_CheckCredentials extends AddModule_Base
{

	private $taskIds;
	private $originalBindDn;

	protected function preprocessInternal()
	{
		$server = Request::post('server');
		$port = Request::post('port');
		$searchbase = Request::post('searchbase', '');
		$binddn = Request::post('binddn');
		$bindpw = Request::post('bindpw');
		$ssl = Request::post('ssl', 'off') === 'on';
		if (empty($server) || empty($binddn) || empty($port)) {
			Message::addError('empty-field');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$parent = null;
		$this->originalBindDn = '';
		$server .= ':' . $port;
		if ($ssl) {
			$uri = "ldaps://$server/";
		} else {
			$uri = "ldap://$server/";
		}
		if (preg_match('#^\w+[/\\\\](\w+)$#', $binddn, $out)) {
			$user = $out[1];
			$this->originalBindDn = str_replace('/', '\\', $binddn);
			$selfSearch = Taskmanager::submit('LdapSearch', array(
					'server' => $uri,
					'searchbase' => $searchbase,
					'binddn' => $this->originalBindDn,
					'bindpw' => $bindpw,
					'username' => $user
			));
			if (!isset($selfSearch['id'])) {
				AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
				return;
			}
			$parent = $selfSearch['id'];
		}
		$ldapSearch = Taskmanager::submit('LdapSearch', array(
				'parentTask' => $parent,
				'server' => $uri,
				'searchbase' => $searchbase,
				'binddn' => $binddn,
				'bindpw' => $bindpw
		));
		if (!isset($ldapSearch['id'])) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
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
		Render::addDialog(Dictionary::translate('config-module', 'adAuth_title'), false, 'sysconfig/ad-checkcredentials', array_merge($this->taskIds, array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => Request::post('server') . ':' . Request::post('port'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'ssl' => Request::post('ssl'),
			'fingerprint' => Request::post('fingerprint'),
			'originalbinddn' => $this->originalBindDn,
			'step' => 'AdAuth_Finish'
			))
		);
	}

}

class AdAuth_Finish extends AddModule_Base
{

	private $taskIds;

	protected function preprocessInternal()
	{
		$binddn = Request::post('binddn');
		$searchbase = Request::post('searchbase');
		if (empty($searchbase)) {
			// If no search base was given, determine it from the dn
			$originalBindDn = str_replace('\\', '/', trim(Request::post('originalbinddn')));
			if (!preg_match('#^([^/]+)/[^/]+$#', $originalBindDn, $out)) {
				Message::addError('value-invalid', 'binddn', $originalBindDn);
				Util::redirect('?do=SysConfig&action=addmodule&step=AdAuth_Start');
			} // $out[1] is the domain
			// Find the domain in the dn
			$i = mb_stripos($binddn, '=' . $out[1] . ',');
			if ($i === false) {
				Message::addError('value-invalid', 'binddn', $out[1]);
				Util::redirect('?do=SysConfig&action=addmodule&step=AdAuth_Start');
			}
			// Now find ',' before it so we get the key
			$i = mb_strrpos(mb_substr($binddn, 0, $i), ',');
			if ($i === false)
				$i = -1;
			$searchbase = mb_substr($binddn, $i + 1);
		}
		$title = Request::post('title');
		if (empty($title))
			$title = 'AD: ' . Request::post('server');
		if ($this->edit === false)
			$module = ConfigModule::getInstance('AdAuth');
		else
			$module = $this->edit;
		$module->setData('server', Request::post('server'));
		$module->setData('searchbase', $searchbase);
		$module->setData('binddn', $binddn);
		$module->setData('bindpw', Request::post('bindpw'));
		$module->setData('home', Request::post('home'));
		$module->setData('ssl', Request::post('ssl', 'off') === 'on');
		if (Request::post('fingerprint')) {
			$module->setData('fingerprint', Request::post('fingerprint'));
		}
		if ($this->edit !== false)
			$ret = $module->update($title);
		else
			$ret = $module->insert($title);
		if (!$ret) {
			Message::addError('value-invalid', 'any', 'any');
			$tgz = false;
		} else {
			$tgz = $module->generate($this->edit === false, NULL, 200);
		}
		if ($tgz === false) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$this->taskIds = array(
			'tm-config' => $tgz,
		);
	}

	protected function renderInternal()
	{
		Render::addDialog(Dictionary::translate('config-module', 'adAuth_title'), false, 'sysconfig/ad-finish', $this->taskIds);
	}

}
