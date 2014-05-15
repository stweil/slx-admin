<?php

error_reporting(E_ALL);

// Autoload classes from ./inc which adhere to naming scheme <lowercasename>.inc.php
function slxAutoloader($class) {
	$file = 'inc/' . preg_replace('/[^a-z0-9]/', '', mb_strtolower($class)) . '.inc.php';
	if (!file_exists($file)) return;
	require_once $file;
}

spl_autoload_register('slxAutoloader');

if (empty($_REQUEST['do'])) {
	// No specific module - set default
	$moduleName = 'main';
} else {
	$moduleName = preg_replace('/[^a-z]/', '', $_REQUEST['do']);
}

$modulePath = 'modules/' . $moduleName . '.inc.php';

if (!file_exists($modulePath)) {
	Util::traceError('Invalid module: ' . $moduleName);
}

// Deserialize any messages
if (isset($_REQUEST['message'])) {
	Message::fromRequest();
}

// CSRF/XSS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Util::verifyToken()) {
	Util::redirect('?do=' . $moduleName);
}

// Load module - it will execute pre-processing, or act upon request parameters
require_once($modulePath);
unset($modulePath);

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

