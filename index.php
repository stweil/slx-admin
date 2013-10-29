<?php

error_reporting(E_ALL);

require_once('inc/user.inc.php');
require_once('inc/render.inc.php');
require_once('inc/menu.inc.php');
require_once('inc/util.inc.php');
require_once('inc/message.inc.php');
require_once('inc/db.inc.php');
require_once('inc/permission.inc.php');

if (empty($_REQUEST['do'])) {
	// No specific module - set default
	$module = 'main';
} else {
	$module = preg_replace('/[^a-z]/', '', $_REQUEST['do']);
}

$module = 'modules/' . $module . '.inc.php';

if (!file_exists($module)) {
	Util::traceError('Invalid module: ' . $module);
}

require_once($module);
unset($module);

$menu = new Menu;
Render::addTemplate('main-menu', $menu);

Message::renderList();

render_module();

Render::output();

