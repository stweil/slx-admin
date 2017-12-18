<?php

class Page_SysConfig extends Page
{

	/**
	 * Holds all the known configuration modules, with title, description, start class for their wizard, etc.
	 *
	 * @var array
	 */
	protected static $moduleTypes = array();

	/**
	 * @var int current locationid, 0 if global
	 */
	private $currentLoc;

	/**
	 * @var array Associative list of known locations
	 */
	private $locations;

	private $haveOverriddenLocations = false;

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
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		// Determine location we're editing
		if (!Module::isAvailable('locations')) {
			$this->locations = array();
			$this->currentLoc = 0;
		} else {
			$this->locations = Location::getLocationsAssoc();
			$this->currentLoc = Request::any('locationid', 0, 'int');
		}
		// Location valid?
		if ($this->currentLoc !== 0 && !isset($this->locations[$this->currentLoc])) {
			Message::addError('locations.invalid-location-id', $this->currentLoc);
			Util::redirect('?do=sysconfig');
		}

		// Action handling

		$action = Request::any('action', 'list');

		// Load all addmodule classes, as they populate the $moduleTypes array
		require_once Page::getModule()->getDir() . '/addmodule.inc.php';
		foreach (glob(Page::getModule()->getDir() . '/addmodule_*.inc.php') as $file) {
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
			if (Request::post('download', 'no') !== 'no') {
				$this->downloadModule();
			}
			if (Request::post('rebuild', 'no') !== 'no') {
				$this->rebuildModule();
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
			// Action "rebuild" (rebuild config.tgz from its modules)
			if (Request::post('rebuild', 'no') !== 'no') {
				$this->rebuildConfig();
			}
		}
	}

	/**
	 * Render module; called by main script when this module page should render
	 * its content.
	 */
	protected function doRender()
	{

		Render::addTemplate('sysconfig_heading');

		$action = Request::any('action', 'list');
		switch ($action) {
		case 'addmodule':
			AddModule_Base::render();
			return;
		case 'addconfig':
			AddConfig_Base::render();
			return;
		case 'list':
			Render::openTag('div', array('class' => 'row'));
			$this->listConfigs();
			if ($this->currentLoc === 0) {
				$this->listModules();
			}
			Render::closeTag('div');
			if ($this->currentLoc === 0) {
				Render::addTemplate('list-legend', array('showLocationBadge' => $this->haveOverriddenLocations));
			}
			Render::addTemplate('js'); // Make this js snippet a template so i18n works
			return;
		case 'module':
			$listid = Request::post('list');
			if ($listid !== false) {
				$this->listModuleContents($listid);
				return;
			}
			break;
		case 'config':
			$listid = Request::post('list');
			if ($listid !== false) {
				$this->listConfigContents($listid);
				return;
			}
			break;
		}
		Message::addError('invalid-action', $action, 'main');
	}

	private function getLocationNames($locations, $ids)
	{
		$ret = array();
		foreach ($ids as $id) {
			settype($id, 'int');
			if (isset($locations[$id])) {
				$ret[] = $locations[$id]['locationname'];
			}
		}
		return implode(', ', $ret);
	}

	/**
	 * List all configurations and configuration modules.
	 */
	private function listConfigs()
	{
		// Configs
		$res = Database::simpleQuery("SELECT c.configid, c.title, c.filepath, c.status,"
			. "   GROUP_CONCAT(DISTINCT cl.locationid) AS loclist, GROUP_CONCAT(cxm.moduleid) AS modlist"
			. " FROM configtgz c"
			. " LEFT JOIN configtgz_x_module cxm USING (configid)"
			. " LEFT JOIN configtgz_location cl ON (c.configid = cl.configid)"
			. " GROUP BY configid"
			. " ORDER BY title ASC");
		$configs = array();
		if ($this->currentLoc !== 0) {
			$locationName = $this->locations[$this->currentLoc]['locationname'];
		} else {
			$locationName = false;
		}
		$hasDefault = false;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (is_null($row['loclist'])) {
				$locList = array();
			} else {
				$locList = explode(',', $row['loclist']);
			}
			$isDefault = in_array((string)$this->currentLoc, $locList, true);
			$hasDefault |= $isDefault;
			if ($this->currentLoc !== 0) {
				$locCount = 0;
			} else {
				$locCount = count($locList);
				if ($isDefault) {
					$locCount--;
				}
			}
			if ($locCount > 0) {
				$this->haveOverriddenLocations = true;
			}
			$configs[] = array(
				'configid' => $row['configid'],
				'config' => $row['title'],
				'modlist' => $row['modlist'],
				'current' => $isDefault,
				'loclist' => $row['loclist'],
				'readableLocList' => $this->getLocationNames($this->locations, $locList),
				'locationCount' => $locCount,
				'needrebuild' => ($row['status'] !== 'OK')
			);
		}
		Render::addTemplate('list-configs', array(
			'locationid' => $this->currentLoc,
			'locationname' => $locationName,
			'havelocations' => Module::isAvailable('locations'),
			'configs' => $configs,
			'inheritConfig' => !$hasDefault,
		));
	}

	private function listModules()
	{
		// Config modules
		$modules = ConfigModule::getAll();
		$types = array_map(function ($mod) { return $mod->moduleType(); }, $modules);
		$titles = array_map(function ($mod) { return $mod->title(); }, $modules);
		array_multisort($types, SORT_ASC, $titles, SORT_ASC, $modules);
		Render::addTemplate('list-modules', array(
			'modules' => $modules,
			'havemodules' => (count($modules) > 0)
		));
	}

	private function listModuleContents($moduleid)
	{
		// fetch the data
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($row === false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
		}

		// find files in that archive
		$status = Taskmanager::submit('ListArchive', array(
			'file' => $row['filepath']
		));
		if (isset($status['id']))
			$status = Taskmanager::waitComplete($status, 4000);
		if (!Taskmanager::isFinished($status) || Taskmanager::isFailed($status)) {
			Taskmanager::addErrorMessage($status);
			Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
		}

		// Sort files for better display
		$dirs = array();
		foreach ($status['data']['entries'] as $file) {
			if ($file['isdir'])
				continue;
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

		// render the template
		Render::addDialog(Dictionary::translate('lang_contentOf') . ' ' . $row['title'], false, 'custom-filelist', array(
			'files' => $list,
		));
	}

	private function listConfigContents($configid)
	{
		// get config name
		$config = Database::queryFirst("SELECT title FROM configtgz WHERE configid = :configid LIMIT 1", array('configid' => $configid));
		if ($config === false) {
			Message::addError('config-invalid', $configid);
			Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
		}
		// fetch the data
		$res = Database::simpleQuery("SELECT module.moduleid, module.title AS moduletitle"
			. " FROM configtgz_module module"
			. " INNER JOIN configtgz_x_module USING (moduleid)"
			. " WHERE configtgz_x_module.configid = :configid"
			. " ORDER BY module.title ASC", array('configid' => $configid));

		$modules = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$modules[] = array(
				'module' => $row['moduletitle'],
				'moduleid' => $row['moduleid']
			);
		}

		// render the template
		Render::addDialog(Dictionary::translate('lang_contentOf') . ' ' . $config['title'], false, 'config-module-list', array(
			'modules' => $modules
		));
	}

	private function activateConfig()
	{
		$configid = Request::post('activate', false, 'int');
		if ($configid === false) {
			Message::addError('main.empty-field');
			Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
		}
		// Validate that either the configid is valid (in case we override for a specific location)
		// or that if the locationid is 0 (=global) that the configid exists, because it's not allowed
		// to unset the global config
		if ($this->currentLoc === 0 || $configid !== 0) {
			$row = Database::queryFirst("SELECT title, filepath FROM configtgz WHERE configid = :configid LIMIT 1", array('configid' => $configid));
			if ($row === false) {
				Message::addError('config-invalid', $configid);
				Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
			}
		}
		$locationid = $this->currentLoc;
		if ($configid === 0) {
			Database::exec("DELETE FROM configtgz_location WHERE locationid = :locationid",
				compact('locationid'));
		} else {
			Database::exec("INSERT INTO configtgz_location (locationid, configid) VALUES (:locationid, :configid)"
				. " ON DUPLICATE KEY UPDATE configid = :configid", compact('locationid', 'configid'));
		}
		Event::activeConfigChanged();
		Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
	}

	private function rebuildConfig()
	{
		$configid = Request::post('rebuild', 'MISSING');
		$config = ConfigTgz::get($configid);
		if ($config === false) {
			Message::addError('config-invalid', $configid);
			Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
		}
		$ret = $config->generate(false, 500); // TODO
		if ($ret === true)
			Message::addSuccess('module-rebuilt', $config->title());
		elseif ($ret === false)
			Message::addError('module-rebuild-failed', $config->title());
		else
			Message::addInfo('module-rebuilding', $config->title());
		Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
	}

	private function delModule()
	{
		$moduleid = Request::post('del', 'MISSING');
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($row === false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=sysconfig');
		}
		$existing = Database::queryFirst("SELECT title FROM configtgz_x_module"
			. " INNER JOIN configtgz USING (configid)"
			. " WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($existing !== false) {
			Message::addError('module-in-use', $row['title'], $existing['title']);
			Util::redirect('?do=sysconfig');
		}
		$task = Taskmanager::submit('DeleteFile', array(
			'file' => $row['filepath']
		));
		if (isset($task['statusCode']) && $task['statusCode'] === TASK_WAITING) {
			$task = Taskmanager::waitComplete($task['id']);
		}
		if (!isset($task['statusCode']) || $task['statusCode'] === TASK_ERROR) {
			Message::addWarning('main.task-error', $task['data']['error']);
		} elseif ($task['statusCode'] === TASK_FINISHED) {
			Message::addSuccess('module-deleted', $row['title']);
		}
		Database::exec("DELETE FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		Util::redirect('?do=sysconfig');
	}

	private function downloadModule()
	{
		$moduleid = Request::post('download', 'MISSING');
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($row === false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=sysconfig');
		}
		if (!Util::sendFile($row['filepath'], $row['title'] . '.tgz'))
			Util::redirect('?do=sysconfig');
		exit(0);
	}

	private function rebuildModule()
	{
		$moduleid = Request::post('rebuild', 'MISSING');
		$module = ConfigModule::get($moduleid);
		if ($module === false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=sysconfig');
		}
		$ret = $module->generate(false, 250);
		if ($ret === true)
			Message::addSuccess('module-rebuilt', $module->title());
		elseif ($ret === false)
			Message::addError('module-rebuild-failed', $module->title());
		else
			Message::addInfo('module-rebuilding', $module->title());
		Util::redirect('?do=sysconfig');
	}

	private function delConfig()
	{
		$configid = Request::post('del', 'MISSING');
		$config = ConfigTgz::get($configid);
		if ($config === false) {
			Message::addError('config-invalid', $configid);
			Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
		}
		if ($config->delete() !== false) {
			Message::addSuccess('config-deleted', $config->title());
		}
		Util::redirect('?do=sysconfig&locationid=' . $this->currentLoc);
	}

	private function initAddModule()
	{
		ConfigModule::loadDb();
		require_once Page::getModule()->getDir() . '/addmodule.inc.php';
		$step = Request::any('step', 'AddModule_Start', 'string');
		if (!class_exists($step) && preg_match('/^([a-zA-Z0-9]+)_/', $step, $out)) {
			require_once Page::getModule()->getDir() . '/addmodule_' . strtolower($out[1]) . '.inc.php';
		}
		AddModule_Base::setStep($step);
	}

	private function initAddConfig()
	{
		ConfigModule::loadDb();
		require_once Page::getModule()->getDir() . '/addconfig.inc.php';
		$step = Request::any('step', 0);
		if ($step === 0)
			$step = 'AddConfig_Start';
		AddConfig_Base::setStep($step);
	}

}
