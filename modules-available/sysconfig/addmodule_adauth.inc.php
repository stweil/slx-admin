<?php

/*
 * Wizard for setting up active directory integration for authentication.
 */

class AdAuth_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		$ADAUTH_COMMON_FIELDS = array('title', 'server', 'searchbase', 'binddn', 'bindpw', 'home', 'homeattr', 'ssl', 'certificate');
		$data = array();
		if ($this->edit !== false) {
			moduleToArray($this->edit, $data, $ADAUTH_COMMON_FIELDS);
			$data['title'] = $this->edit->title();
			$data['edit'] = $this->edit->id();
		}
		postToArray($data, $ADAUTH_COMMON_FIELDS, true);
		$obdn = Request::post('originalbinddn');
		if (!empty($obdn)) {
			$data['binddn'] = $obdn;
		}
		if (preg_match('/^(.*)\:(636|3269|389|3268)$/', $data['server'], $out)) {
			$data['server'] = $out[1];
		}
		$data['step'] = 'AdAuth_CheckConnection';
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad-start', $data);
	}

}

class AdAuth_CheckConnection extends AddModule_Base
{

	private $scanTask;
	private $server;

	protected function preprocessInternal()
	{
		$this->server = Request::post('server');
		$binddn = Request::post('binddn');
		$ssl = Request::post('ssl', 'off') === 'on';
		if (empty($this->server) || empty($binddn)) {
			Message::addError('main.empty-field');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
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
				'ports' => $ports,
				'certificate' => Request::post('certificate', '')
		));
		if (!isset($this->scanTask['id'])) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
	}

	protected function renderInternal()
	{
		$data = array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => $this->server,
			'searchbase' => Util::normalizeDn(Request::post('searchbase')),
			'binddn' => Util::normalizeDn(Request::post('binddn')),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'homeattr' => Request::post('homeattr'),
			'ssl' => Request::post('ssl'),
			'certificate' => Request::post('certificate', ''),
			'taskid' => $this->scanTask['id']
		);
		$data['prev'] = 'AdAuth_Start';
		if (preg_match('#^\w+[/\\\\]\w+$#', Request::post('binddn')) || strlen(Request::post('searchbase')) < 2) {
			$data['next'] = 'AdAuth_SelfSearch';
		} elseif (empty($data['homeattr'])) {
			$data['next'] = 'AdAuth_HomeAttrCheck';
		} else {
			$data['next'] = 'AdAuth_CheckCredentials';
		}
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad_ldap-checkconnection', $data);
	}

}

class AdAuth_SelfSearch extends AddModule_Base
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
		if ($ssl && !Request::post('fingerprint')) {
			Message::addError('main.error-read', 'fingerprint');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		if (empty($server) || empty($binddn) || empty($port)) {
			Message::addError('main.empty-field');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$this->originalBindDn = '';
		// Fix bindDN if short name given
		//
		if ($ssl) { // Use the specific AD ports so the domain\username bind works
			$uri = "ldaps://$server:3269/";
		} else {
			$uri = "ldap://$server:3268/";
		}
		preg_match('#^\w+[/\\\\](\w+)$#', $binddn, $out);
		$user = $out[1];
		$this->originalBindDn = str_replace('/', '\\', $binddn);
		$selfSearch = Taskmanager::submit('LdapSearch', array(
				'server' => $uri,
				'searchbase' => $searchbase,
				'binddn' => $this->originalBindDn,
				'bindpw' => $bindpw,
				'filter' => "sAMAccountName=$user"
		));
		if (!isset($selfSearch['id'])) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$this->taskIds['self-search'] = $selfSearch['id'];
	}

	protected function renderInternal()
	{
		$data = array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => Request::post('server'),
			'port' => Request::post('port'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'homeattr' => Request::post('homeattr'),
			'ssl' => Request::post('ssl') === 'on',
			'fingerprint' => Request::post('fingerprint'),
			'certificate' => Request::post('certificate', ''),
			'originalbinddn' => $this->originalBindDn,
			'prev' => 'AdAuth_Start'
		);
		if (empty($data['homeattr'])) {
			$data['next'] = 'AdAuth_HomeAttrCheck';
		} else {
			$data['next'] = 'AdAuth_CheckCredentials';
		}
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad-selfsearch',
			array_merge($this->taskIds, $data));
	}

}

class AdAuth_HomeAttrCheck extends AddModule_Base
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
			Message::addError('main.error-read', 'fingerprint');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		if (empty($server) || empty($binddn) || empty($port)) {
			Message::addError('main.empty-field');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		if ($ssl) {
			$uri = "ldaps://$server:$port/";
		} else {
			$uri = "ldap://$server:$port/";
		}
		preg_match('#^(\w+=[^,]+),#', $binddn, $out);
		$filter = $out[1];
		$data = array(
			'server' => $uri,
			'searchbase' => $searchbase,
			'binddn' => $binddn,
			'bindpw' => $bindpw,
			'filter' => $filter
		);
		$selfSearch = Taskmanager::submit('LdapSearch', $data);
		if (!isset($selfSearch['id'])) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$this->taskIds['self-search'] = $selfSearch['id'];
	}

	protected function renderInternal()
	{
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad-selfsearch', array_merge($this->taskIds, array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => Request::post('server'),
			'port' => Request::post('port'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'homeattr' => Request::post('homeattr'),
			'ssl' => Request::post('ssl') === 'on',
			'fingerprint' => Request::post('fingerprint'),
			'certificate' => Request::post('certificate', ''),
			'originalbinddn' => Request::post('originalbinddn'),
			'tryHomeAttr' => true,
			'prev' => 'AdAuth_Start',
			'next' => 'AdAuth_CheckCredentials'
			))
		);
	}

}

class AdAuth_CheckCredentials extends AddModule_Base
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
			Message::addError('main.error-read', 'fingerprint');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		if (empty($server) || empty($binddn) || empty($port)) {
			Message::addError('main.empty-field');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		// Test query 4 users
		if ($ssl) {
			$uri = "ldaps://$server:$port/";
		} else {
			$uri = "ldap://$server:$port/";
		}
		$ldapSearch = Taskmanager::submit('LdapSearch', array(
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
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad_ldap-checkcredentials', array_merge($this->taskIds, array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => Request::post('server') . ':' . Request::post('port'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'homeattr' => Request::post('homeattr'),
			'ssl' => Request::post('ssl') === 'on',
			'fingerprint' => Request::post('fingerprint'),
			'certificate' => Request::post('certificate', ''),
			'originalbinddn' => Request::post('originalbinddn'),
			'prev' => 'AdAuth_Start',
			'next' => 'AdAuth_Finish'
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
				Message::addError('main.value-invalid', 'binddn', $originalBindDn);
				Util::redirect('?do=SysConfig&action=addmodule&step=AdAuth_Start');
			} // $out[1] is the domain
			// Find the domain in the dn
			$i = mb_stripos($binddn, '=' . $out[1] . ',');
			if ($i === false) {
				Message::addError('main.value-invalid', 'binddn', $out[1]);
				Util::redirect('?do=SysConfig&action=addmodule&step=AdAuth_Start');
			}
			// Now find ',' before it so we get the key
			$i = mb_strrpos(mb_substr($binddn, 0, $i), ',');
			if ($i === false)
				$i = -1;
			$searchbase = mb_substr($binddn, $i + 1);
		} else {
			$somedn = Request::post('somedn', false);
			if (!empty($somedn)) {
				$i = stripos($somedn, $searchbase);
				if ($i !== false) {
					$searchbase = substr($somedn, $i, strlen($searchbase));
				}
			}
		}
		$title = Request::post('title');
		if (empty($title))
			$title = 'AD: ' . Request::post('server');
		if ($this->edit === false)
			$module = ConfigModule::getInstance('AdAuth');
		else
			$module = $this->edit;
		$ssl = Request::post('ssl', 'off') === 'on';
		$module->setData('server', Request::post('server'));
		$module->setData('searchbase', $searchbase);
		$module->setData('binddn', $binddn);
		$module->setData('bindpw', Request::post('bindpw'));
		$module->setData('home', Request::post('home'));
		$module->setData('homeattr', Request::post('homeattr'));
		$module->setData('certificate', Request::post('certificate'));
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
			Message::addError('main.value-invalid', 'any', 'any');
			$tgz = false;
		} else {
			$parent = $this->stopOldInstance();
			$tgz = $module->generate($this->edit === false, $parent);
		}
		if ($tgz === false) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
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
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad-finish', $this->taskIds);
	}

}
