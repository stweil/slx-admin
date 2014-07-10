<?php

class Page_SysConfig extends Page
{
	
	/**
	 * Holds all the known configuration modules, with title, description, start class for their wizard, etc.
	 * @var array
	 */
	protected static $moduleTypes = array();

	/**
	 * Add a known configuration module. Every addmoule_* file should call this
	 * for its module provided.
	 *
	 * @param string $id Internal identifier for the module
	 * @param string $startClass Class to start wizard for creating such a module
	 * @param string $title Title of this module type
	 * @param string $description Description for this module type
	 * @param string $group Title for group this module type belongs to
	 * @param bool $unique Can only one such module be added to a config?
	 * @param int $sortOrder Lower comes first, alphabetical ordering otherwiese
	 */
	public static function addModule($id, $startClass, $title, $description, $group, $unique, $sortOrder = 0)
	{
		self::$moduleTypes[$id] = array(
			'startClass' => $startClass,
			'title' => $title,
			'description' => $description,
			'group' => $group,
			'unique' => $unique,
			'sortOrder' => $sortOrder
		);
	}
	
	/**
	 * 
	 * @return array All registered module types
	 */
	public static function getModuleTypes()
	{
		return self::$moduleTypes;
	}

	protected function doPreprocess()
	{
		User::load();
		
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}

		$action = Request::any('action', 'list');
		
		// Load all addmodule classes, as they populate the $moduleTypes array
		require_once 'modules/sysconfig/addmodule.inc.php';
		foreach (glob('modules/sysconfig/addmodule_*.inc.php') as $file) {
			require_once $file;
		}

		// Action: "addmodule" (upload new module)
		if ($action === 'addmodule') {
			$this->initAddModule();
			AddModule_Base::preprocess();
		}

		if ($action === 'module') {
			// Action: "delmodule" (delete module)
			if (Request::post('del', 'no') !== 'no') {
				$this->delModule();
			}
		}
		
		// Action: "addconfig" (compose config from one or more modules)
		if ($action === 'addconfig') {
			$this->initAddConfig();
			AddConfig_Base::preprocess();
		}

		if ($action === 'config') {
			// Action: "delconfig" (delete config)
			if (Request::post('del', 'no') !== 'no') {
				$this->delConfig();
			}
			// Action "activate" (set sysconfig as active)
			if (Request::post('activate', 'no') !== 'no') {
				$this->activateConfig();
			}
		}
	}

	/**
	 * Render module; called by main script when this module page should render
	 * its content.
	 */
	protected function doRender()
	{
		Render::setTitle('Lokalisierung');
		
		$action = Request::any('action', 'list');
		switch ($action) {
		case 'addmodule':
			AddModule_Base::render();
			break;
		case 'addconfig':
			AddConfig_Base::render();
			break;
		case 'list':
			$this->listConfigs();
			break;
		case 'module':
			$this->listModuleContents();
			break;
		default:
			Message::addError('invalid-action', $action);
			break;
		}
	}

	/**
	 * List all configurations and configuration modules.
	 */
	private function listConfigs()
	{
		// Configs
		$res = Database::simpleQuery("SELECT configid, title, filepath FROM configtgz ORDER BY title ASC");
		$configs = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$configs[] = array(
				'configid' => $row['configid'],
				'config' => $row['title'],
				'current' => readlink('/srv/openslx/www/boot/default/config.tgz') === $row['filepath']
			);
		}
		// Config modules
		$res = Database::simpleQuery("SELECT moduleid, title FROM configtgz_module ORDER BY title ASC");
		$modules = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$modules[] = array(
				'moduleid' => $row['moduleid'],
				'module' => $row['title']
			);
		}
		Render::addTemplate('page-sysconfig-main', array(
			'configs' => $configs,
			'modules' => $modules
		));
	}
	
	private function listModuleContents()
	{
		// fetch the data
		$moduleid = Request::post('list', 'MISSING');
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($row == false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=SysConfig');
		}
		
		// find files in that archive
		$taskStatus = Taskmanager::submit('ListArchive', array(
			'file' => $row['filepath']
			));
		
		if (isset($taskStatus['id'])) {
			
			$status = Taskmanager::waitComplete($taskStatus['id']);
			Taskmanager::release($taskStatus['id']);
			if (!isset($status['statusCode'])) {
				//$this->tmError();
			}
			if ($status['statusCode'] != TASK_FINISHED) {
				//$this->taskError($status);
			}
			// Sort files for better display
			$dirs = array();
			foreach ($status['data']['entries'] as $file) {
				if ($file['isdir']) continue;
				$dirs[dirname($file['name'])][] = $file;
			}
			ksort($dirs);
			$list = array();
			foreach ($dirs as $dir => $files) {
				$list[] = array(
						'name' => $dir,
						'isdir' => true
				);
				sort($files);
				foreach ($files as $file) {
					$file['size'] = Util::readableFileSize($file['size']);
					$list[] = $file;
				}
			}
		} else {
			// task failed, redirect 
			Util::redirect('?do=SysConfig');
		} 
			
		
		// render the template
		Render::addDialog('Inhalt von "' . $row['title'] . '"', false, 'sysconfig/custom-filelist', array(
				'files' => $list,
		));
	}
	
	private function activateConfig()
	{
		$configid = Request::post('activate', 'MISSING');
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz WHERE configid = :configid LIMIT 1", array('configid' => $configid));
		if ($row === false) {
			Message::addError('config-invalid', $configid);
			Util::redirect('?do=SysConfig');
		}
		$task = Taskmanager::submit('LinkConfigTgz', array(
			'destination' => $row['filepath']
		));
		if (isset($task['statusCode']) && $task['statusCode'] === TASK_WAITING) {
			$task = Taskmanager::waitComplete($task['id']);
		}
		if (!isset($task['statusCode']) || $task['statusCode'] === TASK_ERROR) {
			Message::addError('task-error', $task['data']['error']);
		} elseif ($task['statusCode'] === TASK_FINISHED) {
			Message::addSuccess('config-activated', $row['title']);
			Trigger::ldadp(); // TODO: Feedback
		}
		Util::redirect('?do=SysConfig');
	}
	
	private function delModule()
	{
		$moduleid = Request::post('del', 'MISSING');
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($row === false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=SysConfig');
		}
		$existing = Database::queryFirst("SELECT title FROM configtgz_x_module"
			. " INNER JOIN configtgz USING (configid)"
			. " WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($existing !== false) {
			Message::addError('module-in-use', $row['title'], $existing['title']);
			Util::redirect('?do=SysConfig');
		}
		$task = Taskmanager::submit('DeleteFile', array(
			'file' => $row['filepath']
		));
		if (isset($task['statusCode']) && $task['statusCode'] === TASK_WAITING) {
			$task = Taskmanager::waitComplete($task['id']);
		}
		if (!isset($task['statusCode']) || $task['statusCode'] === TASK_ERROR) {
			Message::addWarning('task-error', $task['data']['error']);
		} elseif ($task['statusCode'] === TASK_FINISHED) {
			Message::addSuccess('module-deleted', $row['title']);
		}
		Database::exec("DELETE FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		Util::redirect('?do=SysConfig');
	}
	
	private function delConfig()
	{
		$configid = Request::post('del', 'MISSING');
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz WHERE configid = :configid LIMIT 1", array('configid' => $configid));
		if ($row === false) {
			Message::addError('config-invalid', $configid);
			Util::redirect('?do=SysConfig');
		}
		$task = Taskmanager::submit('DeleteFile', array(
			'file' => $row['filepath']
		));
		if (isset($task['statusCode']) && $task['statusCode'] === TASK_WAITING) {
			$task = Taskmanager::waitComplete($task['id']);
		}
		if (!isset($task['statusCode']) || $task['statusCode'] === TASK_ERROR) {
			Message::addWarning('task-error', $task['data']['error']);
		} elseif ($task['statusCode'] === TASK_FINISHED) {
			Message::addSuccess('module-deleted', $row['title']);
		}
		Database::exec("DELETE FROM configtgz WHERE configid = :configid LIMIT 1", array('configid' => $configid));
		Util::redirect('?do=SysConfig');
	}
	
	private function initAddModule()
	{
		$step = Request::any('step', 0);
		if ($step === 0) $step = 'AddModule_Start';
		AddModule_Base::setStep($step);
	}
	
	private function initAddConfig()
	{
		$step = Request::any('step', 0);
		if ($step === 0) $step = 'AddConfig_Start';
		require_once 'modules/sysconfig/addconfig.inc.php';
		foreach (glob('modules/sysconfig/addconfig_*.inc.php') as $file) {
			require_once $file;
		}
		AddConfig_Base::setStep($step);
	}

}
