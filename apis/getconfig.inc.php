<?php

/*
 * For compatibility with old slx-admin, where apis were not connected to a module.
 * This is getconfig, which belongs to baseconfig logically.
 */

if (!Module::isAvailable('baseconfig')) {
	Util::traceError('Module baseconfig not available');
}

require 'modules/baseconfig/api.inc.php';