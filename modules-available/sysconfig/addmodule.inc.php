<?php

/**
 * Addmodule subpage base - makes sure
 * we have the two required methods preprocess and render
 */
abstract class AddModule_Base
{

	/**
	 * Holds the instance for the currently executing step
	 * @var \AddModule_Base
	 */
	private static $instance = false;
	
	/**
	 * Instance of ConfigModule we're editing. False if not editing but creating.
	 * @var \ConfigModule
	 */
	protected $edit = false;

	/**
	 * 
	 * @param string $step name of class representing the current step
	 */
	public static function setStep($step)
	{
		if (empty($step) || !class_exists($step) || get_parent_class($step) !== 'AddModule_Base') {
			Message::addError('invalid-action', $step);
			Util::redirect('?do=SysConfig');
		}
		self::$instance = new $step();
		if (Request::any('edit')) {
			self::$instance->edit = ConfigModule::get(Request::any('edit'));
			if (self::$instance->edit === false)
				Util::traceError('Invalid module id for editing');
			if (!preg_match('/^' . self::$instance->edit->moduleType() . '_/', $step))
				Util::traceError('Module to edit is of different type!');
			Util::addRedirectParam('edit', self::$instance->edit->id());
		}
	}

	protected function tmError()
	{
		Message::addError('main.taskmanager-error');
		Util::redirect('?do=SysConfig');
	}

	protected function taskError($status)
	{
		if (isset($status['data']['error'])) {
			$error = $status['data']['error'];
		} elseif (isset($status['statusCode'])) {
			$error = $status['statusCode'];
		} else {
			$error = Dictionary::translate('lang_unknwonTaskManager');
		}
		Message::addError('main.task-error', $error);
		Util::redirect('?do=SysConfig');
	}

	/**
	 * Called before any HTML rendering happens, so you can
	 * prepare stuff, validate input, and optionally redirect
	 * early if something is wrong, or you received post
	 * data etc.
	 */
	protected function preprocessInternal()
	{
		// void
	}

	/**
	 * Do page rendering.
	 */
	protected function renderInternal()
	{
		// void
	}
	
	/**
	 * Handle ajax stuff
	 */
	protected function ajaxInternal()
	{
		// void
	}
	
	public static function preprocess()
	{
		if (self::$instance === false) {
			Util::traceError('No step instance yet');
		}
		self::$instance->preprocessInternal();
	}
	
	public static function render()
	{
		if (self::$instance === false) {
			Util::traceError('No step instance yet');
		}
		if (self::$instance->edit !== false) {
			Message::addInfo('replacing-module', self::$instance->edit->title());
		}
		self::$instance->renderInternal();
	}
	
	public static function ajax()
	{
		if (self::$instance === false) {
			Util::traceError('No step instance yet');
		}
		self::$instance->ajaxInternal();
	}

}

/**
 * Start dialog for adding module. Here the user
 * selects which kind of module they want to add.
 */
class AddModule_Start extends AddModule_Base
{

	protected function renderInternal()
	{
		$title = $order = array();
		$mods = ConfigModule::getList();
		foreach ($mods as $module) {
			$title[] = $module['title'];
			$order[] = $module['sortOrder'];
		}
		array_multisort($order, SORT_ASC, $title, SORT_ASC, $mods);
		Render::addDialog(Dictionary::translate('lang_moduleAdd'), false, 'start', array('modules' => array_values($mods)));
	}

}

/*
 * Helper functions to set/get a batch of vars from/to post variables or a module
 */

/**
 * 
 * @param \ConfigModule $module
 * @param array $array
 * @param array $keys
 */
function moduleToArray($module, &$array, $keys)
{
	foreach ($keys as $key) {
		$array[$key] = $module->getData($key);
	}
}

/**
 * 
 * @param \ConfigModule $module
 * @param array $array
 * @param array $keys
 */
function arrayToModule($module, $array, $keys)
{
	foreach ($keys as $key) {
		$module->setData($key, $array[$key]);
	}
}
/**
 * 
 * @param array $array
 * @param array $keys
 */
function postToArray(&$array, $keys, $ignoreMissing = false)
{
	foreach ($keys as $key) {
		$val = Request::post($key, '--not-in-post');
		if ($ignoreMissing && $val === '--not-in-post') continue;
		$array[$key] = $val;
	}
}
