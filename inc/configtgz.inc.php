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
	
	public function generate()
	{
		if (!($this->configId > 0) || !is_array($this->modules) || $this->file === false)
			Util::traceError ('configId <= 0 or modules not array in ConfigTgz::rebuild()');
		$files = array();
		$successStatus = 'OK';
		foreach ($this->modules as $module) {
			if (!empty($module['filepath']) && file_exists($module['filepath'])) {
				$files[] = $module['filepath'];
				if ($module['status'] !== 'OK')
					$successStatus = 'OUTDATED';
			} else {
				$successStatus = 'OUTDATED';
			}
		}
		// Hand over to tm
		$task = Taskmanager::submit('RecompressArchive', array(
			'inputFiles' => $files,
			'outputFile' => $this->file
		));
		// Wait for completion
		if (!Taskmanager::isFailed($task) && !Taskmanager::isFinished($task))
			$task = Taskmanager::waitComplete($task, 5000);
		// Failed...
		if (Taskmanager::isFailed($task)) {
			Taskmanager::addErrorMessage($task);
			$successStatus = file_exists($this->file) ? 'OUTDATED' : 'MISSING';
		}
		Database::exec("UPDATE configtgz SET status = :status WHERE configid = :configid LIMIT 1", array(
			'configid' => $this->configId,
			'status' => $successStatus
		));
		return $successStatus;
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
	
}
