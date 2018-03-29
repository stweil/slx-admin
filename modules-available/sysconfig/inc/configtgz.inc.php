<?php

class ConfigTgz
{
	
	private $configId = 0;
	private $configTitle = false;
	private $file = false;
	private $modules = array();

	private function __construct()
	{
		;
	}

	public function id()
	{
		return $this->configId;
	}
	
	public function title()
	{
		return $this->configTitle;
	}
	
	public function areAllModulesUpToDate()
	{
		if (!$this->configId > 0)
			Util::traceError('ConfigTgz::areAllModulesUpToDate called on un-inserted config.tgz!');
		foreach ($this->modules as $module) {
			if (!empty($module['filepath']) && file_exists($module['filepath'])) {
				if ($module['status'] !== 'OK')
					return false;
			} else {
				return false;
			}
		}
		return true;
	}
	
	public function isActive()
	{
		return readlink(CONFIG_HTTP_DIR . '/default/config.tgz') === $this->file;
	}
	
	public function getModuleIds()
	{
		$ret = array();
		foreach ($this->modules as $module) {
			$ret[] = $module['moduleid'];
		}
		return $ret;
	}
		
	public function update($title, $moduleIds)
	{
		if (!is_array($moduleIds))
			return false;
		$this->configTitle = $title;
		$this->modules = array();
		// Get all modules to put in config
		$idstr = '0'; // Passed directly in query. Make sure no SQL injection is possible
		foreach ($moduleIds as $module) {
			$idstr .= ',' . (int)$module; // Casting to int should make it safe
		}
		$res = Database::simpleQuery("SELECT moduleid, moduletype, filepath, status FROM configtgz_module WHERE moduleid IN ($idstr)");
		// Delete old connections
		Database::exec("DELETE FROM configtgz_x_module WHERE configid = :configid", array('configid' => $this->configId));
		// Make connection
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			Database::exec("INSERT INTO configtgz_x_module (configid, moduleid) VALUES (:configid, :moduleid)", array(
				'configid' => $this->configId,
				'moduleid' => $row['moduleid']
			));
			$this->modules[] = $row;
		}
		// Update name
		Database::exec("UPDATE configtgz SET title = :title, status = :status WHERE configid = :configid LIMIT 1", array(
			'configid' => $this->configId,
			'title' => $title,
			'status' => 'OUTDATED'
		));
		return true;
	}
	
	/**
	 * 
	 * @param bool $deleteOnError
	 * @param int $timeoutMs
	 * @return string - OK (success)
	 *		- OUTDATED (updating failed, but old version still exists)
	 *		- MISSING (failed and no old version available)
	 */
	public function generate($deleteOnError = false, $timeoutMs = 0)
	{
		if (!($this->configId > 0) || !is_array($this->modules) || $this->file === false)
			Util::traceError ('configId <= 0 or modules not array in ConfigTgz::rebuild()');
		$files = array();
		// Get all config modules for system config
		foreach ($this->modules as $module) {
			if (!empty($module['filepath']) && file_exists($module['filepath'])) {
				$files[] = $module['filepath'];
			}
			if ($module['moduletype'] === 'SshConfig') {
				// HACK XXX TODO Global + SSH ugly
				self::rebuildEmptyGlobalConfig();
			}
		}

		$task = self::recompress($files, $this->file);

		// Wait for completion
		if ($timeoutMs > 0 && !Taskmanager::isFailed($task) && !Taskmanager::isFinished($task))
			$task = Taskmanager::waitComplete($task, $timeoutMs);
		if ($task === true || (isset($task['statusCode']) && $task['statusCode'] === TASK_FINISHED)) {
			// Success!
			$this->markUpdated();
			return true;
		}
		if (!is_array($task) || !isset($task['id']) || Taskmanager::isFailed($task)) {
			// Failed...
			Taskmanager::addErrorMessage($task);
			if (!$deleteOnError)
				$this->markFailed();
			else
				$this->delete();
			return false;
		}
		// Still running, add callback
		TaskmanagerCallback::addCallback($task, 'cbConfTgzCreated', array(
			'configid' => $this->configId,
			'deleteOnError' => $deleteOnError
		));
		return $task['id'];
	}

	public function delete()
	{
		if ($this->configId === 0)
			Util::traceError('ConfigTgz::delete called with invalid config id!');
		$ret = Database::exec("DELETE FROM configtgz WHERE configid = :configid LIMIT 1", array(
				'configid' => $this->configId
			), true) !== false;
		if ($ret !== false) {
			if ($this->file !== false)
				Taskmanager::submit('DeleteFile', array('file' => $this->file), true);
			$this->configId = 0;
			$this->modules = false;
			$this->file = false;
		}
		return $ret;
	}
	
	public function markOutdated()
	{
		if ($this->configId === 0)
			Util::traceError('ConfigTgz::markOutdated called with invalid config id!');
		return $this->mark('OUTDATED');
	}
	
	private function markUpdated()
	{
		if ($this->configId === 0)
			Util::traceError('ConfigTgz::markUpdated called with invalid config id!');
		if ($this->areAllModulesUpToDate())
			return $this->mark('OK');
		return $this->mark('OUTDATED');
	}
	
	private function markFailed()
	{
		if ($this->configId === 0)
			Util::traceError('ConfigTgz::markFailed called with invalid config id!');
		if ($this->file === false || !file_exists($this->file))
			return $this->mark('MISSING');
		return $this->mark('OUTDATED');
	}
	
	private function mark($status)
	{
		Database::exec("UPDATE configtgz SET status = :status WHERE configid = :configid LIMIT 1", array(
			'configid' => $this->configId,
			'status' => $status
		));
		return $status;
	}

	/*
	 * Static part
	 */

	/**
	 * @param string[] $files source files to include
	 * @param string $destFile where to store final result
	 * @return false|array taskmanager task
	 */
	private static function recompress($files, $destFile)
	{
		// Get stuff other modules want to inject
		$handler = function($hook) {
			include $hook->file;
			return isset($file) ? $file : false;
		};
		foreach (Hook::load('config-tgz') as $hook) {
			$file = $handler($hook);
			if ($file !== false) {
				$files[] = $file;
			}
		}

		// Hand over to tm
		return Taskmanager::submit('RecompressArchive', array(
			'inputFiles' => $files,
			'outputFile' =>$destFile
		));
	}

	/**
	 * Marks all modules as outdated and triggers generate()
	 * on each one. This mostly makes sense to call if a global module
	 * that is injected via a hook has changed.
	 */
	public static function rebuildAllConfigs()
	{
		Database::exec("UPDATE configtgz SET status = :status", array(
			'status' => 'OUTDATED'
		));
		$res = Database::simpleQuery("SELECT configid FROM configtgz");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$configTgz = self::get($row['configid']);
			if ($configTgz !== false) {
				$configTgz->generate();
			}
		}
		// Build the global "empty" config that just includes global hooks
		self::rebuildEmptyGlobalConfig();
	}

	/**
	 * Rebuild the general "empty" config that only contains global hook modules
	 * and forced ones.
	 */
	private static function rebuildEmptyGlobalConfig()
	{
		static $onceOnly = false;
		if ($onceOnly)
			return;
		$onceOnly = true;
		// HACK TODO XXX -- just stuff (global) ssh config into this one for now, needs proper fix :-(
		$res = Database::simpleQuery("SELECT DISTINCT cm.filepath FROM configtgz_module cm
				INNER JOIN configtgz_x_module cxm USING (moduleid)
				INNER JOIN configtgz_location cl USING (configid)
				WHERE cm.moduletype = 'SshConfig' AND cm.status = 'OK'
				ORDER BY locationid ASC");
		$extra = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (file_exists($row['filepath'])) {
				$extra[] = $row['filepath'];
				break;
			}
		}
		self::recompress($extra, SysConfig::GLOBAL_MINIMAL_CONFIG);
	}

	/**
	 * @param string $title Title of config
	 * @param int[] $moduleIds Modules to include in config
	 * @return false|ConfigTgz The module instance, false on error
	 */
	public static function insert($title, $moduleIds)
	{
		if (!is_array($moduleIds))
			return false;
		$instance = new ConfigTgz;
		$instance->configTitle = $title;
		// Create output file name (config.tgz)
		do {
			$instance->file = CONFIG_TGZ_LIST_DIR . '/config-' . Util::sanitizeFilename($instance->configTitle) . '-' . mt_rand() . '-' . time() . '.tgz';
		} while (file_exists($instance->file));
		Database::exec("INSERT INTO configtgz (title, filepath, status) VALUES (:title, :filepath, :status)", array(
			'title' => $instance->configTitle,
			'filepath' => $instance->file,
			'status' => 'MISSING'
		));
		$instance->configId = Database::lastInsertId();
		$instance->modules = array();
		// Get all modules to put in config
		$idstr = '0'; // Passed directly in query. Make sure no SQL injection is possible
		foreach ($moduleIds as $module) {
			$idstr .= ',' . (int)$module; // Casting to int should make it safe
		}
		$res = Database::simpleQuery("SELECT moduleid, filepath, status FROM configtgz_module WHERE moduleid IN ($idstr)");
		// Make connection
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			Database::exec("INSERT INTO configtgz_x_module (configid, moduleid) VALUES (:configid, :moduleid)", array(
				'configid' => $instance->configId,
				'moduleid' => $row['moduleid']
			));
			$instance->modules[] = $row;
		}
		return $instance;
	}

	public static function get($configId)
	{
		$ret = Database::queryFirst("SELECT configid, title, filepath FROM configtgz WHERE configid = :configid", array(
			'configid' => $configId
		));
		if ($ret === false)
			return false;
		$instance = new ConfigTgz;
		$instance->configId = $ret['configid'];
		$instance->configTitle = $ret['title'];
		$instance->file = $ret['filepath'];
		$ret = Database::simpleQuery("SELECT moduleid, filepath, status FROM configtgz_x_module "
			. " INNER JOIN configtgz_module USING (moduleid) "
			. " WHERE configid = :configid", array('configid' => $instance->configId));
		$instance->modules = array();
		while ($row = $ret->fetch(PDO::FETCH_ASSOC)) {
			$instance->modules[] = $row;
		}
		return $instance;
	}

	/**
	 * @param int $moduleId ID of config module
	 * @return ConfigTgz[]|false
	 */
	public static function getAllForModule($moduleId)
	{
		$res = Database::simpleQuery("SELECT configid, title, filepath FROM configtgz_x_module "
			. " INNER JOIN configtgz USING (configid) "
			. " WHERE moduleid = :moduleid", array(
			'moduleid' => $moduleId
		));
		if ($res === false)
			return false;
		$list = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$instance = new ConfigTgz;
			$instance->configId = $row['configid'];
			$instance->configTitle = $row['title'];
			$instance->file = $row['filepath'];
			$innerRes = Database::simpleQuery("SELECT moduleid, filepath, status FROM configtgz_x_module "
				. " INNER JOIN configtgz_module USING (moduleid) "
				. " WHERE configid = :configid", array('configid' => $instance->configId));
			$instance->modules = array();
			while ($innerRow = $innerRes->fetch(PDO::FETCH_ASSOC)) {
				$instance->modules[] = $innerRow;
			}
			$list[] = $instance;
		}
		return $list;
	}

	/**
	 * Called when (re)generating a config tgz failed, so we can
	 * update the status in the DB and add a server log entry.
	 *
	 * @param array $task
	 * @param array $args contains 'configid' and optionally 'deleteOnError'
	 */
	public static function generateFailed($task, $args)
	{
		if (!isset($args['configid']) || !is_numeric($args['configid'])) {
			EventLog::warning('Ignoring generateFailed event as it has no configid assigned.');
			return;
		}
		$config = self::get($args['configid']);
		if ($config === false) {
			EventLog::warning('generateFailed callback for config id ' . $args['configid'] . ', but no instance could be generated.');
			return;
		}
		if (isset($task['data']['error']))
			$error = $task['data']['error'];
		elseif (isset($task['data']['messages']))
			$error = $task['data']['messages'];
		else
			$error = '';
		EventLog::failure("Generating config.tgz '" . $config->configTitle . "' failed.", $error);
		if ($args['deleteOnError'])
			$config->delete();
		else
			$config->markFailed();
	}

	/**
	 * (Re)generating a config tgz succeeded. Update db entry.
	 *
	 * @param array $args contains 'configid' and optionally 'deleteOnError'
	 */
	public static function generateSucceeded($args)
	{
		if (!isset($args['configid']) || !is_numeric($args['configid'])) {
			EventLog::warning('Ignoring generateSucceeded event as it has no configid assigned.');
			return;
		}
		$config = self::get($args['configid']);
		if ($config === false) {
			EventLog::warning('generateSucceeded callback for config id ' . $args['configid'] . ', but no instance could be generated.');
			return;
		}
		$config->markUpdated();
	}
	
}
