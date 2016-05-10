<?php

require_once 'config.php';

if (CONFIG_SQL_PASS === '%MYSQL_OPENSLX_PASS%') {
	Header('Content-Type: text/plain; charset=utf-8');
	die("The server has not been configured yet. Please log in to the server via console/SSH and follow the instructions."
		. "\n\n"
		. "Der Server wurde noch nicht konfiguriert. Bitte loggen Sie sich auf der Konsole oder per SSH auf dem Server ein"
		. " und folgen Sie den Instruktionen.");
}

require_once('inc/user.inc.php');

/**
 * Page class which all module's pages must be extending from
 */
abstract class Page
{

	protected function doPreprocess()
	{
		
	}

	protected function doRender()
	{
		
	}

	protected function doAjax()
	{
		
	}

	public static function preprocess()
	{
		self::$instance->doPreprocess();
	}

	public static function render()
	{
		$pageTitle = self::$module->getPageTitle();
		if ($pageTitle !== false) {
			Render::setTitle($pageTitle);
		}
		self::$instance->doRender();
	}

	public static function ajax()
	{
		self::$instance->doAjax();
	}
	
	public static function getModule()
	{
		return self::$module;
	}

	/**
	 * @var \Page
	 */
	private static $instance = false;
	
	/**
	 * @var \Module
	 */
	private static $module = false;

	public static function init()
	{
		$name = empty($_REQUEST['do']) ? 'Main' : $_REQUEST['do'];
		$name = preg_replace('/[^A-Za-z]/', '', $name);
		$name = strtolower($name);
		Module::init();
		self::$module = Module::get($name);
		if (self::$module === false) {
			Util::traceError('Invalid Module: ' . $name);
		}
		self::$module->activate();
		self::$instance = self::$module->newPage();
	}

}

// Error reporting (hopefully goind to stderr, not being printed on pages)
error_reporting(E_ALL);

// Set variable if this is an ajax request
if ((isset($_REQUEST['async'])) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
	define('AJAX', true);
} else {
	define('AJAX', false);
}
define('API', false);

// Autoload classes from ./inc which adhere to naming scheme <lowercasename>.inc.php
function slxAutoloader($class)
{
	$file = 'inc/' . preg_replace('/[^a-z0-9]/', '', mb_strtolower($class)) . '.inc.php';
	if (!file_exists($file))
		return;
	require_once $file;
}

spl_autoload_register('slxAutoloader');

// Now determine which module to run
Page::init();

// Deserialize any messages to display
if (!AJAX && isset($_REQUEST['message'])) {
	Message::fromRequest();
}

// CSRF/XSS check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	User::load();
	if (!Util::verifyToken()) {
		if (AJAX) {
			die('CSRF/XSS? Missing token in POST request!');
		} else {
			Util::redirect('?do=Main');
		}
	}
}

// AJAX Stuff? Just do so. Otherwise, run preprocessing
if (AJAX) {
	Page::ajax();
	exit(0);
}

// Normal mode - preprocess first....
Page::preprocess();

// Generate Main menu
Dashboard::createMenu();

Message::renderList();

// Render page. If the module wants to output anything, it will be done here...
Page::render();

if (defined('CONFIG_DEBUG') && CONFIG_DEBUG) {
	Message::addWarning('main.debug-mode');
}

if (defined('CONFIG_FOOTER')) {
	Render::addTemplate('footer', array('text' => CONFIG_FOOTER), 'main');
}

Render::addTemplate('tm-callback-trigger', array(), 'main');

// Send page to client.
Render::output();
