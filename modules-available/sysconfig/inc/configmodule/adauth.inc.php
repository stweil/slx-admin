<?php

class ConfigModule_AdAuth extends ConfigModuleBaseLdap
{

	const MODID = 'AdAuth';

}

ConfigModule::registerModule(
	ConfigModule_AdAuth::MODID, // ID
	Dictionary::translateFileModule('sysconfig', 'config-module', 'adAuth_title'), // Title
	Dictionary::translateFileModule('sysconfig', 'config-module', 'adAuth_description'), // Description
	Dictionary::translateFileModule('sysconfig', 'config-module', 'group_authentication'), // Group
	false // Only one per config?
);
