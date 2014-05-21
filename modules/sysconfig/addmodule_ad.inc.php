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
		Session::set('ad_check', false);
		Session::save();
		Render::addDialog('Active Directory Authentifizierung', false, 'sysconfig/ad-start', array(
			'step' => 'AdModule_CheckConnection',
			'server' => Request::post('server'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
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
		/*
		$data = Taskmanager::submit('LdapSearch', array(
			'id' => $this->taskId,
			'uri' => ''
		));
		*/
		$ldapSearch = Taskmanager::submit('DummyTask', array());
		if (isset($ldapSearch['id'])) {
			$dummy = Taskmanager::submit('DummyTask', array('parentTask' => $ldapSearch['id']));
		}
		if (!isset($ldapSearch['id']) || !isset($dummy['id'])) {
			AddModule_Base::setStep('AdModule_Start'); // Continues with AdModule_Start for render()
			return;
		}
		$this->taskIds = array(
			'tm-search' => $ldapSearch['id'],
			'tm-dummy' => $dummy['id']
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
		$data = json_encode(array(
			'server' => Request::post('server'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
		));
		Database::exec("INSERT INTO configtgz_module (title, moduletype, filename, contents) "
			. " VALUES (:title, 'AD_AUTH', '', :content)", array(
				'title' => 'AD: ' . Request::post('server'),
				'content' => $data));
		$id = Database::lastInsertId();
		$name = CONFIG_TGZ_LIST_DIR . '/modules/AD_AUTH_id_' . $id . '.' . mt_rand() . '.tgz';
		Database::exec("UPDATE configtgz_module SET filename = :filename WHERE moduleid = :id LIMIT 1", array(
			'id' => $id,
			'filename' => $name
		));
		$tgz = Taskmanager::submit('DummyTask', array());
		if (isset($tgz['id'])) {
			$ldadp = Taskmanager::submit('DummyTask', array('parentTask' => $tgz['id']));
		}
		if (!isset($tgz['id']) || !isset($ldadp['id'])) {
			AddModule_Base::setStep('AdModule_Start'); // Continues with AdModule_Start for render()
			return;
		}
		$this->taskIds = array(
			'tm-module' => $tgz['id'],
			'tm-ldadp' => $ldadp['id'] 
		);
	}
	
	protected function renderInternal()
	{
		Render::addDialog('Active Directory Authentifizierung', false, 'sysconfig/ad-finish', 
			$this->taskIds
		);
	}

}