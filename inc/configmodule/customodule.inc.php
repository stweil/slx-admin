<?php

ConfigModules::registerModule(
	ConfigModule_CustomModule::MODID, // ID
	Dictionary::translate('config-module', 'custom_title'), // Title
	Dictionary::translate('config-module', 'custom_description'), // Description
	Dictionary::translate('config-module', 'group_generic'), // Group
	false, // Only one per config?
	100 // Sort order
);

class ConfigModule_CustomModule extends ConfigModule
{
	const MODID = 'CustomModule';

}
