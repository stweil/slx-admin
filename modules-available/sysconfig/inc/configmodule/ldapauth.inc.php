<?php

class ConfigModule_LdapAuth extends ConfigModuleBaseLdap
{

	const MODID = 'LdapAuth';

	protected function preTaskmanagerHook(&$config)
	{
		// Just set the flag so the taskmanager job knows we're dealing with a normal ldap server,
		// not AD scheme
		$config['plainldap'] = true;
	}

}

ConfigModule::registerModule(
	ConfigModule_LdapAuth::MODID, // ID
	Dictionary::translateFileModule('sysconfig', 'config-module', 'ldapAuth_title'), // Title
	Dictionary::translateFileModule('sysconfig', 'config-module', 'ldapAuth_description'), // Description
	Dictionary::translateFileModule('sysconfig', 'config-module', 'group_authentication'), // Group
	false // Only one per config?
);
