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
	 * 
	 * @param type $step
	 * @return \AddModule_Base
	 */
	public static function setStep($step)
	{
		if (empty($step) || !class_exists($step) || get_parent_class($step) !== 'AddModule_Base') {
			Message::addError('invalid-action', $step);
			Util::redirect('?do=SysConfig');
		}
		self::$instance = new $step();
	}

	protected function tmError()
	{
		Message::addError('taskmanager-error');
		Util::redirect('?do=SysConfig');
	}

	protected function taskError($status)
	{
		if (isset($status['data']['error'])) {
			$error = $status['data']['error'];
		} elseif (isset($status['statusCode'])) {
			$error = $status['statusCode'];
		} else {
			$error = Dictionary::translate('lang_unknwonTaskManager'); // TODO: No text
		}
		Message::addError('task-error', $error);
		Util::redirect('?do=SysConfig');
	}

	/**
	 * Called before any HTML rendering happens, so you can
	 * pepare stuff, validate input, and optionally redirect
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
		$mods = Page_SysConfig::getModuleTypes();
		foreach ($mods as $module) {
			$title[] = $module['title'];
			$order[] = $module['sortOrder'];
		}
		array_multisort($order, SORT_ASC, $title, SORT_ASC, $mods);
		Render::addDialog(Dictionary::translate('lang_moduleAdd'), false, 'sysconfig/start', array('modules' => array_values($mods)));
	}

}
