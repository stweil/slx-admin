<?php

error_reporting(E_ALL);

require_once('inc/user.inc.php');
require_once('inc/render.inc.php');
require_once('inc/menu.inc.php');
require_once('inc/util.inc.php');
require_once('inc/message.inc.php');
require_once('inc/db.inc.php');
require_once('inc/permission.inc.php');
require_once('inc/crypto.inc.php');
require_once('inc/validator.inc.php');

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

// Display any messages
if (isset($_REQUEST['message'])) {
	Message::fromRequest();
}

// Load module - it will execute pre-processing, or act upon request parameters
require_once($module);
unset($module);

// Main menu
$menu = new Menu;
Render::addTemplate('main-menu', $menu);

Message::renderList();

// Render module. If the module wants to output anything, it will be done here
render_module();

if (defined('CONFIG_DEBUG') && CONFIG_DEBUG) {
	Message::addWarning('debug-mode');
}

// Send page to client.
Render::output();

