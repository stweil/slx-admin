<?php

require_once 'config.php';

require_once 'dictionary.php';


/**
 * Page class which all "modules" must be extending from
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
		self::$instance->doRender();
	}

	public static function ajax()
	{
		self::$instance->doAjax();
	}

	/**
	 *
	 * @var \Page
	 */
	private static $instance = false;

	public static function set($name)
	{
		$name = preg_replace('/[^A-Za-z]/', '', $name);
		$modulePath = 'modules/' . strtolower($name) . '.inc.php';
		if (!file_exists($modulePath)) {
			Util::traceError('Invalid module file: ' . $modulePath);
		}
		require_once $modulePath;
		$className = 'Page_' . $name;
		if (!class_exists($className) || get_parent_class($className) !== 'Page') {
			Util::traceError('Module not found: ' . $name);
		}
		self::$instance = new $className();
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
Page::set(empty($_REQUEST['do']) ? 'Main' : $_REQUEST['do']);

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
$menu = new Menu;
Render::addTemplate('main-menu', $menu);

Message::renderList();

// Render page. If the module wants to output anything, it will be done here...
Page::render();

if (defined('CONFIG_DEBUG') && CONFIG_DEBUG) {
	Message::addWarning('debug-mode');
}

// Send page to client.
Render::output();
