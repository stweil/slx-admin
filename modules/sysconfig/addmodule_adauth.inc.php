<?php

/*
 * Wizard for setting up active directory integration for authentication.
 */

class AdAuth_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		Session::set('ad_check', false);
		Session::save();
		Render::addDialog(Dictionary::translate('lang_adAuthentication'), false, 'sysconfig/ad-start', array(
			'step' => 'AdAuth_CheckConnection',
			'title' => Request::post('title'),
			'server' => Request::post('server'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home')
		));
	}

}

class AdAuth_CheckConnection extends AddModule_Base
{

	private $taskIds;
	private $originalBindDn;

	protected function preprocessInternal()
	{
		$server = Request::post('server');
		$searchbase = Request::post('searchbase', '');
		$binddn = Request::post('binddn');
		$bindpw = Request::post('bindpw');
		if (empty($server) || empty($binddn)) {
			Message::addError('empty-field');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$parent = null;
		$this->originalBindDn = '';
		if (preg_match('#^\w+[/\\\\](\w+)$#', $binddn, $out)) {
			$user = $out[1];
			$this->originalBindDn = str_replace('/', '\\', $binddn);
			$selfSearch = Taskmanager::submit('LdapSearch', array(
					'server' => $server,
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
				'server' => $server,
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
		Render::addDialog(Dictionary::translate('lang_adAuthentication'), false, 'sysconfig/ad-checkconnection', array_merge($this->taskIds, array(
			'title' => Request::post('title'),
			'server' => Request::post('server'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
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
		$module = ConfigModule::getInstance('AdAuth');
		$module->setData('server', Request::post('server'));
		$module->setData('searchbase', $searchbase);
		$module->setData('binddn', $binddn);
		$module->setData('bindpw', Request::post('bindpw'));
		$module->setData('home', Request::post('home'));
		if (!$module->insert($title)) {
			Message::addError('value-invalid', 'any', 'any');
			$tgz = false;
		} else {
			$tgz = $module->generate(true);
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
		Render::addDialog(Dictionary::translate('lang_adAuthentication'), false, 'sysconfig/ad-finish', $this->taskIds);
	}

}
