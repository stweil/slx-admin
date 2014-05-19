<?php

/*
 * Wizard for setting up active directory integration for authentication.
 */

AddModule_Base::addModule('active_directory', 'AdModule_Start', 'Active Directory Authentifizierung',
	'Mit diesem Modul ist die Anmeldung an den Client PCs mit den Benutzerkonten eines Active Directory'
	. ' möglich. Je nach Konfiguration ist auch die Nutzung eines Benutzerverzeichnisses auf dem Client möglich.'
);

class AdModule_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		Session::set('ad_stuff', false);
		Render::addDialog('Active Directory Authentifizierung', false, 'sysconfig/ad-start', array(
			'step' => 'AdModule_CheckConnection',
			'server' => Request::post('server'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
		));
	}

}

class AdModule_CheckConnection extends AddModule_Base
{
	
	private $taskId = false;
	
	protected function preprocessInternal()
	{
		$server = Request::post('server');
		$searchbase = Request::post('searchbase');
		$binddn = Request::post('binddn');
		$bindpw = Request::post('bindpw');
		if (empty($server) || empty($searchbase) || empty($binddn)) {
			Message::addError('empty-field');
			AddModule_Base::setStep('AdModule_Start');
			return;
		}
		$this->taskId = 'ad_' . mt_rand() . '-' . microtime(true);
		Taskmanager::submit('LdapSearch', array(
			'id' => $this->taskId,
			'uri' => ''
		), true);
	}
	
	protected function renderInternal()
	{
		Message::addInfo('missing-file');
	}
	
}