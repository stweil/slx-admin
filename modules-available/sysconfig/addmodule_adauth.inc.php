<?php

/*
 * Wizard for setting up active directory integration for authentication.
 */

define('AD_SHORT_REGEX', '#^([^\[\]\:;\|\=\+\?\<\>\*"/\\\\,]+)[/\\\\]([^\[\]\:;\|\=\+\?\<\>\*"/\\\\,]+)$#');
define('AD_BOTH_REGEX', '#^[^\[\]\:;\|\=\+\?\<\>\*"/\\\\,]+[/\\\\@][^\[\]\:;\|\=\+\?\<\>\*"/\\\\,]+$#');
define('AD_AT_REGEX', '#^([^\[\]\:;\|\=\+\?\<\>\*"/\\\\,]+)@([^\[\]\:;\|\=\+\?\<\>\*"/\\\\,]+)$#');

class AdAuth_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		$ADAUTH_COMMON_FIELDS = array('title', 'server', 'searchbase', 'binddn', 'bindpw', 'home', 'homeattr', 'ssl', 'fixnumeric', 'certificate', 'mapping');
		$data = array();
		if ($this->edit !== false) {
			moduleToArray($this->edit, $data, $ADAUTH_COMMON_FIELDS);
			$data['title'] = $this->edit->title();
			$data['edit'] = $this->edit->id();
		}
		if ($data['fixnumeric'] === false) {
			$data['fixnumeric'] = 's';
		}
		postToArray($data, $ADAUTH_COMMON_FIELDS, true);
		$obdn = Request::post('originalbinddn');
		if (!empty($obdn)) {
			$data['binddn'] = $obdn;
		}
		if (isset($data['server']) && preg_match('/^(.*)\:(636|3269|389|3268)$/', $data['server'], $out)) {
			$data['server'] = $out[1];
		}
		if (isset($data['homeattr']) && !isset($data['mapping']['homemount']) && strtolower($data['homeattr']) !== 'homedirectory') {
			$data['mapping']['homemount'] = $data['homeattr'];
		}
		$data['step'] = 'AdAuth_CheckConnection';
		$data['map_empty'] = true;
		$data['mapping'] = ConfigModuleBaseLdap::getMapping(isset($data['mapping']) ? $data['mapping'] : false, $data['map_empty']);
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad-start', $data);
	}

}

class AdAuth_CheckConnection extends AddModule_Base
{

	private $scanTask;
	private $server;

	private $searchBase;

	private $bindDn;

	protected function preprocessInternal()
	{
		$this->bindDn = Ldap::normalizeDn(Request::post('binddn', '', 'string'));
		$this->searchBase = Ldap::normalizeDn(Request::post('searchbase', '', 'string'));
		$this->server = Request::post('server');
		$binddn = Request::post('binddn');
		$ssl = Request::post('ssl', 'off') === 'on';
		if (empty($this->server)) {
			Message::addError('main.parameter-empty', 'server');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		if (empty($binddn)) {
			Message::addError('main.parameter-empty', 'binddn');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		if ((preg_match(AD_AT_REGEX, $this->bindDn) > 0) && (strlen($this->searchBase) < 2)) {
			Message::addError('main.parameter-empty', 'searchBase');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		if (preg_match('/^([^\:]+)\:(\d+)$/', $this->server, $out)) {
			$ports = array($out[2]);
			$this->server = $out[1];
			// Test the default ports twice since the other one might not return all required data (home directory)
		} elseif ($ssl) {
			$ports = array(636, 3269, 636);
		} else {
			$ports = array(389, 3268, 389);
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
		$mapping = Request::post('mapping', false, 'array');
		$data = array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => $this->server,
			'searchbase' => $this->searchBase,
			'binddn' => $this->bindDn,
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'ssl' => Request::post('ssl'),
			'fixnumeric' => Request::post('fixnumeric'),
			'certificate' => Request::post('certificate', ''),
			'taskid' => $this->scanTask['id'],
			'mapping' => ConfigModuleBaseLdap::getMapping($mapping),
		);
		$data['prev'] = 'AdAuth_Start';
		if ((preg_match(AD_BOTH_REGEX, $this->bindDn) > 0) || (strlen($this->searchBase) < 2)) {
			$data['next'] = 'AdAuth_SelfSearch';
		} elseif (empty($mapping['homemount'])) {
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
		$server = $binddn = $port = null;
		$searchbase = Request::post('searchbase', '');
		$bindpw = Request::post('bindpw');
		$ssl = Request::post('ssl', 'off') === 'on';
		if ($ssl && !Request::post('fingerprint')) {
			Message::addError('main.error-read', 'fingerprint');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		foreach (['server', 'binddn', 'port'] as $var) {
			$$var = Request::post($var, null);
			if (empty($$var)) {
				Message::addError('main.parameter-empty', $var);
				AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
				return;
			}
		}
		$this->originalBindDn = '';
		// Fix bindDN if short name given
		//
		if ($ssl) { // Use the specific AD ports so the domain\username bind works
			$uri = "ldaps://$server:3269/";
		} else {
			$uri = "ldap://$server:3268/";
		}

		$selfSearchBase = Ldap::getSelfSearchBase($binddn, $searchbase);
		// Set up selfSearch task
		$taskData = array(
			'server' => $uri,
			'searchbase' => $selfSearchBase,
			'bindpw' => $bindpw,
		);
		if (preg_match(AD_SHORT_REGEX, $binddn, $out) && !empty($out[2])) {
			$this->originalBindDn = str_replace('/', '\\', $binddn);
			$taskData['filter'] = 'sAMAccountName=' . $out[2];
		} elseif (preg_match(AD_AT_REGEX, $binddn, $out) && !empty($out[1])) {
			$this->originalBindDn = $binddn;
			$taskData['filter'] = 'userPrincipalName=' . $binddn;
		} elseif (preg_match('/^cn\=([^\=]+),.*?dc\=([^\=]+),/i', Ldap::normalizeDn($binddn), $out)) {
			if (empty($selfSearchBase)) {
				$this->originalBindDn = $out[2] . '\\' . $out[1];
				$taskData['filter'] = 'sAMAccountName=' . $out[1];
			} else {
				$this->originalBindDn = $binddn;
				$taskData['filter'] = 'distinguishedName=' . Ldap::normalizeDn($binddn);
			}
		} else {
			Message::addError('could-not-determine-binddn', $binddn);
			$this->originalBindDn = $binddn;
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$taskData['binddn'] = $this->originalBindDn;
		$selfSearch = Taskmanager::submit('LdapSearch', $taskData);
		if (!isset($selfSearch['id'])) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$this->taskIds['self-search'] = $selfSearch['id'];
	}

	protected function renderInternal()
	{
		$mapping = Request::post('mapping', false, 'array');
		$data = array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => Request::post('server'),
			'port' => Request::post('port'),
			'searchbase' => Request::post('searchbase'),
			'binddn' => Request::post('binddn'),
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'ssl' => Request::post('ssl') === 'on',
			'fixnumeric' => Request::post('fixnumeric'),
			'fingerprint' => Request::post('fingerprint'),
			'certificate' => Request::post('certificate', ''),
			'originalbinddn' => $this->originalBindDn,
			'mapping' => ConfigModuleBaseLdap::getMapping($mapping),
			'prev' => 'AdAuth_Start'
		);
		if (empty($mapping['homemount'])) {
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
		$server = $binddn = $port = null;
		$searchbase = Request::post('searchbase', '');
		$bindpw = Request::post('bindpw');
		$ssl = Request::post('ssl', 'off') === 'on';
		if ($ssl && !Request::post('fingerprint')) {
			Message::addError('main.error-read', 'fingerprint');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		foreach (['server', 'binddn', 'port'] as $var) {
			$$var = Request::post($var, null);
			if (empty($$var)) {
				Message::addError('main.parameter-empty', $var);
				AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
				return;
			}
		}
		if ($ssl) {
			$uri = "ldaps://$server:$port/";
		} else {
			$uri = "ldap://$server:$port/";
		}
		$selfSearchBase = Ldap::getSelfSearchBase($binddn, $searchbase);
		preg_match('#^(\w+\=[^\=]+),#', $binddn, $out);
		$filter = $out[1];
		$data = array(
			'server' => $uri,
			'searchbase' => $selfSearchBase,
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
				'ssl' => Request::post('ssl') === 'on',
				'fixnumeric' => Request::post('fixnumeric'),
				'fingerprint' => Request::post('fingerprint'),
				'certificate' => Request::post('certificate', ''),
				'originalbinddn' => Request::post('originalbinddn'),
				'tryHomeAttr' => true,
				'mapping' => ConfigModuleBaseLdap::getMapping(Request::post('mapping', false, 'array')),
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
		$server = $binddn = $port = null;
		$searchbase = Request::post('searchbase', '');
		$bindpw = Request::post('bindpw');
		$ssl = Request::post('ssl', 'off') === 'on';
		if ($ssl && !Request::post('fingerprint')) {
			Message::addError('main.error-read', 'fingerprint');
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		foreach (['server', 'binddn', 'port'] as $var) {
			$$var = Request::post($var, null);
			if (empty($$var)) {
				Message::addError('main.parameter-empty', $var);
				AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
				return;
			}
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
			'bindpw' => $bindpw,
			'mapping' => Request::post('mapping', false, 'array'),
		));
		if (!isset($ldapSearch['id'])) {
			AddModule_Base::setStep('AdAuth_Start'); // Continues with AdAuth_Start for render()
			return;
		}
		$this->taskIds = array(
			'tm-search' => $ldapSearch['id']
		);
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
				'fixnumeric' => Request::post('fixnumeric'),
				'fingerprint' => Request::post('fingerprint'),
				'certificate' => Request::post('certificate', ''),
				'originalbinddn' => Request::post('originalbinddn'),
				'mapping' => ConfigModuleBaseLdap::getMapping(Request::post('mapping', false, 'array')),
				'prev' => 'AdAuth_Start',
				'next' => 'AdAuth_HomeDir'
			))
		);
	}

}

class AdAuth_HomeDir extends AddModule_Base
{

	private $searchbase;
	private $binddn;

	protected function preprocessInternal()
	{
		$this->binddn = Request::post('binddn');
		$this->searchbase = Request::post('searchbase');
		if (empty($this->searchbase)) {
			// If no search base was given, determine it from the dn
			$originalBindDn = str_replace('\\', '/', trim(Request::post('originalbinddn')));
			if (!preg_match(AD_SHORT_REGEX, $originalBindDn, $out)) {
				Message::addError('main.value-invalid', 'binddn', $originalBindDn);
				Util::redirect('?do=SysConfig&action=addmodule&step=AdAuth_Start');
			} // $out[1] is the domain
			// Find the domain in the dn
			$i = mb_stripos($this->binddn, '=' . $out[1] . ',');
			if ($i === false) {
				Message::addError('main.value-invalid', 'binddn', $out[1]);
				Util::redirect('?do=SysConfig&action=addmodule&step=AdAuth_Start');
			}
			// Now find ',' before it so we get the key
			$i = mb_strrpos(mb_substr($this->binddn, 0, $i), ',');
			if ($i === false)
				$i = -1;
			$this->searchbase = mb_substr($this->binddn, $i + 1);
		} else {
			$somedn = Request::post('somedn', false);
			if (!empty($somedn)) {
				$i = stripos($somedn, $this->searchbase);
				if ($i !== false) {
					$this->searchbase = substr($somedn, $i, strlen($this->searchbase));
				}
			}
		}
	}

	protected function renderInternal()
	{
		$data = array(
			'edit' => Request::post('edit'),
			'title' => Request::post('title'),
			'server' => Request::post('server'),
			'searchbase' => $this->searchbase,
			'binddn' => $this->binddn,
			'bindpw' => Request::post('bindpw'),
			'home' => Request::post('home'),
			'homeattr' => Request::post('homeattr'),
			'ssl' => Request::post('ssl') === 'on',
			'fixnumeric' => Request::post('fixnumeric'),
			'fingerprint' => Request::post('fingerprint'),
			'certificate' => Request::post('certificate', ''),
			'originalbinddn' => Request::post('originalbinddn'),
			'mapping' => ConfigModuleBaseLdap::getMapping(Request::post('mapping', false, 'array')),
			'prev' => 'AdAuth_Start',
			'next' => 'AdAuth_Finish'
		);
		if ($this->edit !== false) {
			foreach (self::getAttributes() as $key) {
				if ($this->edit->getData($key)) {
					$data[$key . '_c'] = 'checked="checked"';
				}
			}
			$letter = $this->edit->getData('shareHomeDrive');
			$data['shareRemapMode_' . $this->edit->getData('shareRemapMode')] = 'selected="selected"';
			foreach (['shareDomain', 'shareHomeMountOpts', 'ldapAttrMountOpts'] as $key) {
				$data[$key] = $this->edit->getData($key);
			}
		} else {
			$data['shareDownloads_c'] = $data['shareMedia_c'] = $data['shareDocuments_c'] = $data['shareRemapCreate_c'] = 'checked="checked"';
			$data['shareRemapMode_1'] = 'selected="selected"';
			$letter = 'H:';
		}
		$data['drives'] = array();
		foreach (range('D', 'Z') as $l) {
			$data['drives'][] = array(
				'drive' => $l . ':',
				'selected' => (strtoupper($letter{0}) === $l) ? 'selected="selected"' : ''
			);
		}
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad_ldap-homedir', $data);
	}

	public static function getAttributes()
	{
		return array('shareRemapMode', 'shareRemapCreate', 'shareDocuments', 'shareDownloads', 'shareDesktop',
			'shareMedia', 'shareOther', 'shareHomeDrive', 'shareDomain', 'credentialPassthrough');
	}

}

class AdAuth_Finish extends AddModule_Base
{

	private $taskIds;

	protected function preprocessInternal()
	{
		$title = Request::post('title');
		if (empty($title))
			$title = 'AD: ' . Request::post('server');
		if ($this->edit === false)
			$module = ConfigModule::getInstance('AdAuth');
		else
			$module = $this->edit;
		$ssl = Request::post('ssl', 'off') === 'on';
		foreach (['searchbase', 'binddn', 'server', 'bindpw', 'home', 'homeattr', 'certificate', 'fixnumeric',
					'ldapAttrMountOpts', 'shareHomeMountOpts'] as $key) {
			$module->setData($key, Request::post($key, '', 'string'));
		}
		$module->setData('ssl', $ssl);
		$module->setData('mapping', Request::post('mapping', false, 'array'));
		foreach (AdAuth_HomeDir::getAttributes() as $key) {
			$value = Request::post($key);
			if (is_numeric($value)) {
				settype($value, 'integer');
			} elseif ($value === 'on') {
				$value = 1;
			} elseif ($value === false) {
				$value = 0;
			}
			$module->setData($key, $value);
		}
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
			$tgz = $module->generate($this->edit === false);
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
		Render::addDialog(Dictionary::translateFile('config-module', 'adAuth_title'), false, 'ad-finish', $this->taskIds);
	}

}
