<?php

/*
 * Wizard for setting up active directory integration for authentication.
 */

Page_SysConfig::addModule('AD_AUTH', 'AdModule_Start', 'Active Directory Authentifizierung',
	'Mit diesem Modul ist die Anmeldung an den Client PCs mit den Benutzerkonten eines Active Directory'
	. ' möglich. Je nach Konfiguration ist auch die Nutzung eines Benutzerverzeichnisses auf dem Client möglich.',
	'Authentifizierung', true
);

class AdModule_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		Session::set('ad_check', false);
		Session::save();
		Render::addDialog('Active Directory Authentifizierung', false, 'sysconfig/ad-start', array(
			'step' => 'AdModule_CheckConnection',
			'server' => Request::post('server'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'token' => Session::get('token')
		));
	}

}

class AdModule_CheckConnection extends AddModule_Base
{
	private $taskIds;

	protected function preprocessInternal()
	{
		$server = Request::post('server');
		$searchbase = Request::post('searchbase');
		$binddn = Request::post('binddn');
		$bindpw = Request::post('bindpw');
		if (empty($server) || empty($searchbase) || empty($binddn)) {
			Message::addError('empty-field');
			AddModule_Base::setStep('AdModule_Start'); // Continues with AdModule_Start for render()
			return;
		}
		$ldapSearch = Taskmanager::submit('LdapSearch', array(
			'home' => Request::post('home'),
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
	}
	
	protected function renderInternal()
	{
		Render::addDialog('Active Directory Authentifizierung', false, 'sysconfig/ad-checkconnection', 
			array_merge($this->taskIds, array(
				'server' => Request::post('server'),
				'searchbase' => Request::post('searchbase'),
				'binddn' => Request::post('binddn'),
				'bindpw' => Request::post('bindpw'),
				'token' => Session::get('token'),
				'home' => Request::post('home'),
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
		$config = ConfigModule::insertAdConfig('AD: ' . Request::post('server'),
			Request::post('server'),
			Request::post('searchbase'),
			Request::post('binddn'),
			Request::post('bindpw', ''),
			Request::post('home', '')
		);
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
		Render::addDialog('Active Directory Authentifizierung', false, 'sysconfig/ad-finish', 
			$this->taskIds
		);
	}

}
