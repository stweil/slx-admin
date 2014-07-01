<?php

/**
 * Addconfig subpage base - makes sure
 * we have the two required methods preprocess and render
 */
abstract class AddConfig_Base
{

	/**
	 * Holds the instance for the currently executing step
	 * @var \AddConfig_Base
	 */
	private static $instance = false;

	/**
	 * 
	 * @param type $step
	 * @return \AddConfig_Base
	 */
	public static function setStep($step)
	{
		if (empty($step) || !class_exists($step) || get_parent_class($step) !== 'AddConfig_Base') {
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
 * Start dialog for adding config. Ask for title,
 * show selection of modules.
 */
class AddConfig_Start extends AddConfig_Base
{

	protected function renderInternal()
	{
		$mods = Page_SysConfig::getModuleTypes();
		$res = Database::simpleQuery("SELECT moduleid, title, moduletype, filepath FROM configtgz_module"
			. " ORDER BY title ASC");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($mods[$row['moduletype']])) {
				$mods[$row['moduletype']] = array(
					'unique' => false,
					'group' => 'Undefined moduletype in addconfig.inc.php'
				);
			}
			if (!isset($mods[$row['moduletype']]['modules'])) {
				$mods[$row['moduletype']]['modules'] = array();
				$mods[$row['moduletype']]['groupid'] = $row['moduletype'];
			}
			if (empty($row['filepath']) || !file_exists($row['filepath'])) $row['missing'] = true;
			$mods[$row['moduletype']]['modules'][] = $row;
		}
		Render::addDialog(Dictionary::translate("lang_configurationCompilation"), false, 'sysconfig/cfg-start', array(
			'step' => 'AddConfig_Finish',
			'groups' => array_values($mods)
		));
	}

}

/**
 * Start dialog for adding config. Ask for title,
 * show selection of modules.
 */
class AddConfig_Finish extends AddConfig_Base
{
	private $task = false;
	private $destFile = false;
	private $title = false;
	private $moduleids = array();
	
	protected function preprocessInternal()
	{
		$modules = Request::post('module');
		$this->title = Request::post('title');
		if (!is_array($modules)) {
			Message::addError('missing-file');
			Util::redirect('?do=SysConfig&action=addconfig');
		}
		if (empty($this->title)) {
			Message::addError('empty-field');
			Util::redirect('?do=SysConfig&action=addconfig');
		}
		// Get all input modules
		$moduleids = '0'; // Passed directly in query. Make sure no SQL injection is possible
		foreach ($modules as $module) {
			$moduleids .= ',' . (int)$module; // Casting to int should make it safe
		}
		$res = Database::simpleQuery("SELECT moduleid, filepath FROM configtgz_module WHERE moduleid IN ($moduleids)");
		$files = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$files[] = $row['filepath'];
			$this->moduleids[] = $row['moduleid'];
		}
		// Create output file name (config.tgz)
		do {
			$this->destFile = CONFIG_TGZ_LIST_DIR . '/config-' . Util::sanitizeFilename($this->title) . '-' . mt_rand() . '.tgz';
		} while (file_exists($this->destFile));
		// Hand over to tm
		$this->task = Taskmanager::submit('RecompressArchive', array(
			'inputFiles' => $files,
			'outputFile' => $this->destFile
		));
	}

	protected function renderInternal()
	{
		if (isset($this->task['statusCode']) && ($this->task['statusCode'] === TASK_WAITING || $this->task['statusCode'] === TASK_PROCESSING)) {
			$this->task = Taskmanager::waitComplete($this->task['id']);
		}
		if ($this->task === false) $this->tmError();
		if (!isset($this->task['statusCode']) || $this->task['statusCode'] !== TASK_FINISHED) $this->taskError($this->task);
		Database::exec("INSERT INTO configtgz (title, filepath) VALUES (:title, :filepath)", array(
			'title' => $this->title,
			'filepath' => $this->destFile
		));
		$confid = Database::lastInsertId();
		foreach ($this->moduleids as $moduleid) {
			Database::exec("INSERT INTO configtgz_x_module (configid, moduleid) VALUES (:configid, :moduleid)", array(
				'configid' => $confid,
				'moduleid' => $moduleid
			));
		}
		Render::addDialog(Dictionary::translate('lang_configurationCompilation'), false, 'sysconfig/cfg-finish', array(
			'configid' => $confid
		));
	}
	
}
