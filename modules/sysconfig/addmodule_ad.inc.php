<?php

/*
 * Wizard for setting up active directory integration for authentication.
 */

Page_SysConfig::addModule('AD_AUTH', 'AdModule_Start', Dictionary::translate('lang_adAuthentication'), Dictionary::translate('lang_adModule'), Dictionary::translate('lang_authentication'), true
);

class AdModule_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		Session::set('ad_check', false);
		Session::save();
		Render::addDialog(Dictionary::translate('lang_adAuthentication'), false, 'sysconfig/ad-start', array(
			'step' => 'AdModule_CheckConnection',
			'title' => Request::post('title'),
			'server' => Request::post('server'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home')
		));
	}

}

class AdModule_CheckConnection extends AddModule_Base
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
			AddModule_Base::setStep('AdModule_Start'); // Continues with AdModule_Start for render()
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
				AddModule_Base::setStep('AdModule_Start'); // Continues with AdModule_Start for render()
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
			AddModule_Base::setStep('AdModule_Start'); // Continues with AdModule_Start for render()
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
			'step' => 'AdModule_Finish'
			))
		);
	}

}

class AdModule_Finish extends AddModule_Base
{

	private $taskIds;

	protected function preprocessInternal()
	{
		$binddn = Request::post('binddn');
		$searchbase = Request::post('searchbase');
		if (empty($searchbase)) {
			$originalBindDn = str_replace('\\', '/', trim(Request::post('originalbinddn')));
			if (!preg_match('#^([^/]+)/[^/]+$#', $originalBindDn, $out)) {
				Message::addError('value-invalid', 'binddn', $originalBindDn);
				Util::redirect('?do=SysConfig&action=addmodule&step=AdModule_Start');
			}
			$i = mb_stripos($binddn, '=' . $out[1] . ',');
			if ($i === false) {
				Message::addError('value-invalid', $binddn, $out[1]);
				Util::redirect('?do=SysConfig&action=addmodule&step=AdModule_Start');
			}
			$searchbase = mb_substr($binddn, $i + 1);
		}
		$title = Request::post('title');
		if (empty($title))
			$title = 'AD: ' . Request::post('server');
		$config = ConfigModule::insertAdConfig($title, Request::post('server'), $searchbase, $binddn, Request::post('bindpw', ''), Request::post('home', ''));
		$config['proxyip'] = Property::getServerIp();
		$tgz = Taskmanager::submit('CreateAdConfig', $config);
		if (!isset($tgz['id'])) {
			AddModule_Base::setStep('AdModule_Start'); // Continues with AdModule_Start for render()
			return;
		}
		$this->taskIds = array(
			'tm-config' => $tgz['id'],
		);
	}

	protected function renderInternal()
	{
		Render::addDialog(Dictionary::translate('lang_adAuthentication'), false, 'sysconfig/ad-finish', $this->taskIds);
	}

}
