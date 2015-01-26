<?php

class Page_SysConfig extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}

		$action = Request::any('action', 'list');

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
		Render::setTitle(Dictionary::translate('lang_location'));

		$action = Request::any('action', 'list');
		switch ($action) {
			case 'addmodule':
				AddModule_Base::render();
				return;
			case 'addconfig':
				AddConfig_Base::render();
				return;
			case 'list':
				$this->listConfigs();
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
		Message::addError('invalid-action', $action);
	}

	/**
	 * List all configurations and configuration modules.
	 */
	private function listConfigs()
	{
		// Configs
		$res = Database::simpleQuery("SELECT configtgz.configid, configtgz.title, configtgz.filepath, configtgz.status, GROUP_CONCAT(configtgz_x_module.moduleid) AS modlist"
			. " FROM configtgz"
			. " INNER JOIN configtgz_x_module USING (configid)"
			. " GROUP BY configid"
			. " ORDER BY title ASC");
		$configs = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$configs[] = array(
				'configid' => $row['configid'],
				'config' => $row['title'],
				'modlist' => $row['modlist'],
				'current' => readlink(CONFIG_HTTP_DIR . '/default/config.tgz') === $row['filepath'],
				'needrebuild' => ($row['status'] !== 'OK')
			);
		}
		// Config modules
		$res = Database::simpleQuery("SELECT moduleid, title, moduletype, status FROM configtgz_module ORDER BY title ASC");
		$modules = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$modules[] = array(
				'moduleid' => $row['moduleid'],
				'moduletype' => $row['moduletype'],
				'module' => $row['title'],
				'iscustom' => ($row['moduletype'] === 'CustomModule' || $row['moduletype'] === 'Branding'),
				'needrebuild' => ($row['status'] !== 'OK')
			);
		}
		Render::addTemplate('sysconfig/_page', array(
			'configs' => $configs,
			'modules' => $modules
		));
		Render::addScriptTop('custom');
		Render::addFooter('<script> $(window).load(function (e) {
			forceTable($("#modtable"));
			forceTable($("#conftable"));
			}); // </script>');
	}

	private function listModuleContents($moduleid)
	{
		// fetch the data
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($row === false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=SysConfig');
		}

		// find files in that archive
		$status = Taskmanager::submit('ListArchive', array(
				'file' => $row['filepath']
		));
		if (isset($status['id']))
			$status = Taskmanager::waitComplete($status, 4000);
		if (!Taskmanager::isFinished($status) || Taskmanager::isFailed($status)) {
			Taskmanager::addErrorMessage($status);
			Util::redirect('?do=SysConfig');
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
		Render::addDialog(Dictionary::translate('lang_contentOf') . ' ' . $row['title'], false, 'sysconfig/custom-filelist', array(
			'files' => $list,
		));
	}
	
	private function listConfigContents($configid)
	{
		// get config name
		$config = Database::queryFirst("SELECT title FROM configtgz WHERE configid = :configid LIMIT 1", array('configid' => $configid));
		if ($config === false) {
			Message::addError('config-invalid', $configid);
			Util::redirect('?do=SysConfig');
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
		Render::addDialog(Dictionary::translate('lang_contentOf') . ' ' . $config['title'], false, 'sysconfig/config-module-list', array(
			'modules' => $modules
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
			Event::activeConfigChanged();
		}
		Util::redirect('?do=SysConfig');
	}

	private function rebuildConfig()
	{
		$configid = Request::post('rebuild', 'MISSING');
		$config = ConfigTgz::get($configid);
		if ($config === false) {
			Message::addError('config-invalid', $configid);
			Util::redirect('?do=SysConfig');
		}
		//$ret = $config->generate(false, 350); // TODO
		$ret = $config->generate(false, 350) === 'OK'; // TODO
		if ($ret === true)
			Message::addSuccess('module-rebuilt', $config->title());
		elseif ($ret === false)
			Message::addError('module-rebuild-failed', $config->title());
		else
			Message::addInfo('module-rebuilding', $config->title());
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

	private function downloadModule()
	{
		$moduleid = Request::post('download', 'MISSING');
		$row = Database::queryFirst("SELECT title, filepath FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleid));
		if ($row === false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=SysConfig');
		}
		if (!Util::sendFile($row['filepath'], $row['title'] . '.tgz'))
			Util::redirect('?do=SysConfig');
		exit(0);
	}

	private function rebuildModule()
	{
		$moduleid = Request::post('rebuild', 'MISSING');
		$module = ConfigModule::get($moduleid);
		if ($module === false) {
			Message::addError('config-invalid', $moduleid);
			Util::redirect('?do=SysConfig');
		}
		$ret = $module->generate(false, 250);
		if ($ret === true)
			Message::addSuccess('module-rebuilt', $module->title());
		elseif ($ret === false)
			Message::addError('module-rebuild-failed', $module->title());
		else
			Message::addInfo('module-rebuilding', $module->title());
		Util::redirect('?do=SysConfig');
	}

	private function delConfig()
	{
		$configid = Request::post('del', 'MISSING');
		$config = ConfigTgz::get($configid);
		if ($config === false) {
			Message::addError('config-invalid', $configid);
			Util::redirect('?do=SysConfig');
		}
		$config->delete();
		Util::redirect('?do=SysConfig');
	}

	private function initAddModule()
	{
		ConfigModule::loadDb();
		require_once 'modules/sysconfig/addmodule.inc.php';
		$step = Request::any('step', 'AddModule_Start');
		if (!class_exists($step) && preg_match('/^([a-zA-Z0-9]+)_/', $step, $out)) {
			require_once 'modules/sysconfig/addmodule_' . strtolower($out[1]) . '.inc.php';
		}
		AddModule_Base::setStep($step);
	}

	private function initAddConfig()
	{
		ConfigModule::loadDb();
		require_once 'modules/sysconfig/addconfig.inc.php';
		$step = Request::any('step', 0);
		if ($step === 0)
			$step = 'AddConfig_Start';
		AddConfig_Base::setStep($step);
	}

}
