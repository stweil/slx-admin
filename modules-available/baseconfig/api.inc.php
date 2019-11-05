<?php

/*
 * We gather all config variables here. First, let other modules generate
 * their desired config vars. Afterwards, add the global config vars from
 * db. If a variable is already set, it will not be overridden by the
 * global setting.
 */


// Prepare ConfigHolder from request data
BaseConfig::prepareFromRequest();

ConfigHolder::add('SLX_NOW', time(), PHP_INT_MAX);

// All done, now output
ConfigHolder::applyPostHooks();
ConfigHolder::outputConfig();

// For quick testing or custom extensions: Include external file that should do nothing
// more than outputting more key-value-pairs. It's expected in the webroot of slxadmin
if (file_exists('client_config_additional.php')) {
	echo "########## client_config_additional.php:\n";
	@include('client_config_additional.php');
}
