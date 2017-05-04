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
			Render::setTitle($pageTitle, false);
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
		$name = preg_replace('/[^A-Za-z_]/', '', $name);
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

// Set variable if this is an ajax request
if ((isset($_REQUEST['async'])) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
	define('AJAX', true);
} else {
	define('AJAX', false);
}
define('API', false);

// Autoload classes from ./inc which adhere to naming scheme <lowercasename>.inc.php
spl_autoload_register(function ($class) {
	$file = 'inc/' . preg_replace('/[^a-z0-9]/', '', mb_strtolower($class)) . '.inc.php';
	if (!file_exists($file))
		return;
	require_once $file;
});


if (defined('CONFIG_DEBUG') && CONFIG_DEBUG) {
	set_error_handler(function ($errno, $errstr, $errfile, $errline) {
		global $SLX_ERRORS;
		$SLX_ERRORS[] = array(
			'errno' => $errno,
			'errstr' => $errstr,
			'errfile' => $errfile,
			'errline' => $errline,
			//'stack' => debug_backtrace(), // TODO
		);
		return false; // Return false so the default error handler will kick in after this
	});
}

// Set HSTS Header if client is using HTTPS
if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
	if (Request::any('hsts') === 'off' || Property::get('webinterface.https-hsts', 'False') !== 'True') {
		Header('Strict-Transport-Security: max-age=0', true);
	} else {
		Header('Strict-Transport-Security: max-age=15768000', true);
	}
}
Header('Expires: Wed, 29 Mar 2007 09:56:28 GMT');
Header("Cache-Control: max-age=0");

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
	ob_start('ob_gzhandler');
	Page::ajax();
	exit(0);
}

// Normal mode - preprocess first....
Page::preprocess();

// Render queued up messages at the top
Message::renderList();

// Render page. If the module wants to output anything, it will be done here...
Page::render();

// We're still executing - generate Main menu
Dashboard::createMenu();

if (defined('CONFIG_DEBUG') && CONFIG_DEBUG) {
	if (empty($SLX_ERRORS)) {
		Message::addWarning('main.debug-mode');
	} else {
		/**
		 * Map an error code into an Error word.
		 *
		 * @param int $code Error code to map
		 * @return array Array of error word.
		 */
		function mapErrorCode($code)
		{
			switch ($code) {
			case E_PARSE:
			case E_ERROR:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_USER_ERROR:
				return 'Fatal Error';
			case E_WARNING:
			case E_USER_WARNING:
			case E_COMPILE_WARNING:
			case E_RECOVERABLE_ERROR:
				return 'Warning';
			case E_NOTICE:
			case E_USER_NOTICE:
				return 'Notice';
			case E_STRICT:
				return 'Strict';
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				return 'Deprecated';
			default :
				return '??Error';
			}
		}
		$dir = preg_quote(dirname(__FILE__), '#');
		foreach ($SLX_ERRORS as &$err) {
			$err['errlevel'] = mapErrorCode($err['errno']);
			$err['errfile'] = preg_replace('#^' . $dir . '#', '', $err['errfile']);
		}
		unset($err, $dir);
		Render::addTemplate('php-errors', array('errors' => $SLX_ERRORS), 'main');
	}
}

if (defined('CONFIG_FOOTER')) {
	Render::addTemplate('footer', array('text' => CONFIG_FOOTER), 'main');
}

Render::addTemplate('tm-callback-trigger', array(), 'main');

// Send page to client.
Render::output();
