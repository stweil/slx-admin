<?php

/**
 * Base class for config modules
 */
abstract class ConfigModule
{

	/**
	 * @var array list of known module types
	 */
	private static $moduleTypes = false;

	private $moduleId = 0;
	private $moduleArchive = false;
	private $moduleTitle = false;
	private $moduleStatus = false;
	private $currentVersion = 0;
	/**
	 * @var false|array Data of module, false if not initialized
	 */
	protected $moduleData = false;
	
	/**
	 * Load all known config module types. This is done
	 * by including *.inc.php from inc/configmodule/. The
	 * files there should in turn call ConfigModule::registerModule()
	 * to register themselves.
	 */
	public static function loadDb()
	{
		if (self::$moduleTypes !== false)
			return;
		self::$moduleTypes = array();
		Module::isAvailable('sysconfig');
		foreach (glob(dirname(__FILE__) . '/configmodule/*.inc.php', GLOB_NOSORT) as $file) {
			require_once $file;
		}
	}

	/**
	 * Get all known config module types.
	 *
	 * @return array list of modules
	 */
	public static function getList()
	{
		self::loadDb();
		return self::$moduleTypes;
	}

	/**
	 * Add a known configuration module. Every inc/configmodule/*.inc.php should call this.
	 *
	 * @param string $id Identifier for the module.
	 * 		The module class must be called ConfigModule_{$id}, the wizard start class {$id}_Start.
	 * 		The wizard's classes should be located in modules/sysconfig/addmodule_{$id_lowercase}.inc.php
	 * @param string $title Title of this module type
	 * @param string $description Description for this module type
	 * @param string $group Title for group this module type belongs to
	 * @param bool $unique Can only one such module be added to a config?
	 * @param int $sortOrder Lower comes first, alphabetical ordering otherwiese
	 */
	public static function registerModule($id, $title, $description, $group, $unique, $sortOrder = 0)
	{
		if (isset(self::$moduleTypes[$id])) {
			Util::traceError("Config Module $id already registered!");
		}
		$moduleClass = 'ConfigModule_' . $id;
		$wizardClass = $id . '_Start';
		if (!class_exists($moduleClass)) {
			Util::traceError("Class $moduleClass does not exist!");
		}
		if (!is_subclass_of($moduleClass, 'ConfigModule')) {
			Util::traceError("$moduleClass does not have ConfigModule as its parent!");
		}
		self::$moduleTypes[$id] = array(
			'title' => $title,
			'description' => $description,
			'group' => $group,
			'unique' => $unique,
			'sortOrder' => $sortOrder,
			'moduleClass' => $moduleClass,
			'wizardClass' => $wizardClass
		);
	}

	/**
	 * Get fresh instance of ConfigModule subclass for given module type.
	 *
	 * @param string $moduleType name of module type
	 * @return false|\ConfigModule module instance
	 */
	public static function getInstance($moduleType)
	{
		self::loadDb();
		if (!isset(self::$moduleTypes[$moduleType])) {
			error_log('Unknown module type: ' . $moduleType);
			return false;
		}
		return new self::$moduleTypes[$moduleType]['moduleClass'];
	}

	public static function instanceFromDbRow($dbRow)
	{
		$instance = self::getInstance($dbRow['moduletype']);
		$instance->currentVersion = $dbRow['version'];
		$instance->moduleArchive = $dbRow['filepath'];
		$instance->moduleData = json_decode($dbRow['contents'], true);
		$instance->moduleId = $dbRow['moduleid'];
		$instance->moduleTitle = $dbRow['title'];
		$instance->moduleStatus = $dbRow['status'];
		if ($instance->moduleVersion() > $instance->currentVersion) {
			$instance->markFailed();
		}
		return $instance;
	}

	/**
	 * Get module instance from id.
	 *
	 * @param int $moduleId module id to get
	 * @return false|\ConfigModule The requested module from DB, or false on error
	 */
	public static function get($moduleId)
	{
		$ret = Database::queryFirst("SELECT moduleid, title, moduletype, filepath, contents, version, status FROM configtgz_module "
				. " WHERE moduleid = :moduleid LIMIT 1", array('moduleid' => $moduleId));
		if ($ret === false)
			return false;
		return self::instanceFromDbRow($ret);
	}

	/**
	 * Get module instances from module type.
	 *
	 * @param int $moduleType module type to get
	 * @return \ConfigModule[]|false The requested modules from DB, or false on error
	 */
	public static function getAll($moduleType = false)
	{
		if ($moduleType === false) {
			$ret = Database::simpleQuery("SELECT moduleid, title, moduletype, filepath, contents, version, status FROM configtgz_module");
		} else {
			$ret = Database::simpleQuery("SELECT moduleid, title, moduletype, filepath, contents, version, status FROM configtgz_module "
					. " WHERE moduletype = :moduletype", array('moduletype' => $moduleType));
		}
		if ($ret === false)
			return false;
		$list = array();
		while ($row = $ret->fetch(PDO::FETCH_ASSOC)) {
			$instance = self::instanceFromDbRow($row);
			if ($instance === false)
				continue;
			$list[] = $instance;
		}
		return $list;
	}

	/**
	 * Get the module version.
	 * 
	 * @return int module version
	 */
	protected abstract function moduleVersion();

	/**
	 * Validate the module's configuration.
	 * 
	 * @return boolean ok or not
	 */
	protected abstract function validateConfig();

	/**
	 * Set module specific data.
	 * 
	 * @param string $key key, name or id of data being set
	 * @param mixed $value Module specific data
	 * @return boolean true if data was successfully set, false otherwise (i.e. invalid data being set)
	 */
	public abstract function setData($key, $value);
	
	/**
	 * Get module specific data.
	 * Can be overridden by modules.
	 * 
	 * @param string $key key, name or id of data to get, or false to get the raw moduleData array
	 * @return mixed Module specific data
	 */
	public function getData($key)
	{
		if ($key === false)
			return $this->moduleData;
		if (!is_array($this->moduleData) || !isset($this->moduleData[$key]))
			return false;
		return $this->moduleData[$key];
	}

	/**
	 * Module specific version of generate.
	 * 
	 * @param string $tgz File name of tgz module to write final output to
	 * @param string $parent Parent task of this task
	 * @return array|boolean true if generation is completed immediately,
	 * 		a task struct if some task needs to be run for generation,
	 * 		false on error
	 */
	protected abstract function generateInternal($tgz, $parent);

	private final function createFileName()
	{
		return CONFIG_TGZ_LIST_DIR . '/modules/'
			. $this->moduleType() . '_id-' . $this->moduleId . '__' . mt_rand() . '-' . time() . '.tgz';
	}

	public function allowDownload()
	{
		return false;
	}

	public function needRebuild()
	{
		return $this->moduleStatus !== 'OK' || $this->currentVersion < $this->moduleVersion();
	}
	
	/**
	 * Get module id (in db)
	 *
	 * @return int id
	 */
	public final function id()
	{
		return $this->moduleId;
	}
	
	/**
	 * Get module title.
	 *
	 * @return string
	 */
	public final function title()
	{
		return $this->moduleTitle;
	}
	
	/**
	 * Get module archive file name.
	 *
	 * @return string tgz file absolute path
	 */
	public final function archive()
	{
		return $this->moduleArchive;
	}

	public final function status()
	{
		return $this->moduleStatus;
	}
	
	/**
	 * Get the module type.
	 *
	 * @return string module type
	 */
	public final function moduleType()
	{
		$name = get_class($this);
		if ($name === false)
			Util::traceError('ConfigModule::moduleType: get_class($this) returned false!');
		// ConfigModule_*
		if (!preg_match('/^ConfigModule_(\w+)$/', $name, $out))
			Util::traceError('ConfigModule::moduleType: get_class($this) returned "' . $name . '"');
		return $out[1];
	}

	/**
	 * Insert this config module into DB. Only
	 * valid if the object was created using the creating constructor,
	 * not if the instance was created using a database entry (static get method).
	 * 
	 * @param string $title display name of the module
	 * @return boolean true if inserted successfully, false if module config is invalid
	 */
	public final function insert($title)
	{
		if ($this->moduleId !== 0)
			Util::traceError('ConfigModule::insert called when moduleId != 0');
		if (!$this->validateConfig())
			return false;
		$this->moduleTitle = $title;
		// Insert
		Database::exec("INSERT INTO configtgz_module (title, moduletype, filepath, contents, version, status) "
			. " VALUES (:title, :type, '', :contents, :version, :status)", array(
			'title' => $title,
			'type' => $this->moduleType(),
			'contents' => json_encode($this->moduleData),
			'version' => 0,
			'status' => 'MISSING'
		));
		$this->moduleId = Database::lastInsertId();
		if (!is_numeric($this->moduleId))
			Util::traceError('Inserting new config module into DB did not yield a numeric insert id');
		$this->moduleArchive = $this->createFileName();
		Database::exec("UPDATE configtgz_module SET filepath = :path WHERE moduleid = :moduleid LIMIT 1", array(
			'path' => $this->moduleArchive,
			'moduleid' => $this->moduleId
		));
		return true;
	}
	
	/**
	 * Update the given module in database. This will not regenerate
	 * the module's tgz.
	 *
	 * @return boolean true on success, false otherwise
	 */
	public final function update($title)
	{
		if ($this->moduleId === 0)
			Util::traceError('ConfigModule::update called when moduleId == 0');
		if (empty($title))
			$title = $this->moduleTitle;
		if (!$this->validateConfig())
			return false;
		// Update
		Database::exec("UPDATE configtgz_module SET title = :title, contents = :contents, status = :status "
			. " WHERE moduleid = :moduleid LIMIT 1", array(
			'moduleid' => $this->moduleId,
			'title' => $title,
			'contents' => json_encode($this->moduleData),
			'status' => 'OUTDATED'
		));
		return true;
	}

	/**
	 * Generate the module's tgz, don't wait for completion.
	 * Updating the database etc. will happen later through a callback.
	 *
	 * @param boolean $deleteOnError if true, the db entry will be deleted if generation failed
	 * @param string $parent Parent task of this task
	 * @param int $timeoutMs maximum time in milliseconds we wait for completion
	 * @return string|boolean task id if deferred generation was started,
	 * 		true if generation succeeded (without using a task or within $timeoutMs)
	 * 		false on error
	 */
	public final function generate($deleteOnError, $parent = NULL, $timeoutMs = 0)
	{
		if ($this->moduleId === 0 || $this->moduleTitle === false)
			Util::traceError('ConfigModule::generateAsync called on uninitialized/uninserted module!');
		$tmpTgz = '/tmp/bwlp-id-' . $this->moduleId . '_' . mt_rand() . '_' . time() . '.tgz';
		$ret = $this->generateInternal($tmpTgz, $parent);
		// Wait for generation if requested
		if ($timeoutMs > 0 && isset($ret['id']) && !Taskmanager::isFinished($ret))
			$ret = Taskmanager::waitComplete($ret, $timeoutMs);
		if ($ret === true || (isset($ret['statusCode']) && $ret['statusCode'] === TASK_FINISHED)) {
			// Already Finished
			if (file_exists($this->moduleArchive) && !file_exists($tmpTgz))
				$tmpTgz = false; // If generateInternal succeeded and there's no tmpTgz, it means the file didn't have to be updated
			return $this->markUpdated($tmpTgz);
		}
		if (!is_array($ret) || !isset($ret['id']) || Taskmanager::isFailed($ret)) {
			if (is_array($ret)) // Failed
				Taskmanager::addErrorMessage($ret);
			if ($deleteOnError)
				$this->delete();
			else
				$this->markFailed();
			return false;
		}
		// Still running, add callback
		TaskmanagerCallback::addCallback($ret, 'cbConfModCreated', array(
			'moduleid' => $this->moduleId,
			'deleteOnError' => $deleteOnError,
			'tmpTgz' => $tmpTgz
		));
		return $ret['id'];
	}

	/**
	 * Delete the module.
	 */
	public final function delete()
	{
		if ($this->moduleId === 0)
			Util::traceError('ConfigModule::delete called with invalid module id!');
		$ret = Database::exec("DELETE FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array(
				'moduleid' => $this->moduleId
			), true) !== false;
		if ($ret !== false) {
			if ($this->moduleArchive)
				Taskmanager::submit('DeleteFile', array('file' => $this->moduleArchive), true);
			$this->moduleId = 0;
			$this->moduleData = false;
			$this->moduleTitle = false;
			$this->moduleArchive = false;
		}
		return $ret;
	}

	private final function markUpdated($tmpTgz)
	{
		if ($this->moduleId === 0)
			Util::traceError('ConfigModule::markUpdated called with invalid module id!');
		if ($this->moduleArchive === false)
			$this->moduleArchive = $this->createFileName();
		// Move file
		if ($tmpTgz === false) {
			if (!file_exists($this->moduleArchive)) {
				EventLog::failure('ConfigModule::markUpdated for "' . $this->moduleTitle . '" called with no tmpTgz and no existing tgz!');
				$this->markFailed();
				return false;
			}
		} elseif (!file_exists($tmpTgz)) {
			EventLog::warning('ConfigModule::markUpdated for tmpTgz="' . $this->moduleTitle . '" called which doesn\'t exist. Doing nothing.');
			return true;
		} else {
			$task = Taskmanager::submit('MoveFile', array(
					'source' => $tmpTgz,
					'destination' => $this->moduleArchive
			));
			$task = Taskmanager::waitComplete($task, 5000);
			if (Taskmanager::isFailed($task) || !Taskmanager::isFinished($task)) {
				if (!API && !AJAX) {
					Taskmanager::addErrorMessage($task);
				} else {
					EventLog::failure('Could not move ' . $tmpTgz . ' to ' . $this->moduleArchive . ' while generating "' . $this->moduleTitle . '"', print_r($task, true));
				}
				$this->markFailed();
				return false;
			}
		}
		// Update DB entry
		$retval = Database::exec("UPDATE configtgz_module SET filepath = :filename, version = :version, status = 'OK' WHERE moduleid = :id LIMIT 1", array(
				'id' => $this->moduleId,
				'filename' => $this->moduleArchive,
				'version' => $this->moduleVersion()
			)) !== false;
		// Update related config.tgzs
		$configs = ConfigTgz::getAllForModule($this->moduleId);
		foreach ($configs as $config) {
			$config->markOutdated();
			$config->generate();
		}
		return $retval;
	}

	private final function markFailed()
	{
		if ($this->moduleId === 0)
			Util::traceError('ConfigModule::markFailed called with invalid module id!');
		if ($this->moduleArchive === false)
			$this->moduleArchive = $this->createFileName();
		if (!file_exists($this->moduleArchive))
			$status = 'MISSING';
		else
			$status = 'OUTDATED';
		return Database::exec("UPDATE configtgz_module SET filepath = :filename, status = :status WHERE moduleid = :id LIMIT 1", array(
				'id' => $this->moduleId,
				'filename' => $this->moduleArchive,
				'status' => $status
			)) !== false;
	}

	################# Callbacks ##############

	/**
	 * Event callback for when the server ip changed.
	 * Override this if you need to handle this, otherwise
	 * the base implementation does nothing.
	 */
	public function event_serverIpChanged()
	{
		// Do::Nothing()
	}

	##################### STATIC CALLBACKS #####################

	/**
	 * Will be called if the server's IP address changes. The event will be propagated
	 * to all config module classes so action can be taken if appropriate.
	 */
	public static function serverIpChanged()
	{
		self::loadDb();
		$list = self::getAll();
		foreach ($list as $mod) {
			$mod->event_serverIpChanged();
		}
	}

	/**
	 * Called when (re)generating a config module failed, so we can
	 * update the status in the DB and add a server log entry.
	 *
	 * @param array $task
	 * @param array $args contains 'moduleid' and optionally 'deleteOnError' and 'tmpTgz'
	 */
	public static function generateFailed($task, $args)
	{
		if (!isset($args['moduleid']) || !is_numeric($args['moduleid'])) {
			EventLog::warning('Ignoring generateFailed event as it has no moduleid assigned.');
			return;
		}
		$module = self::get($args['moduleid']);
		if ($module === false) {
			EventLog::warning('generateFailed callback for module id ' . $args['moduleid'] . ', but no instance could be generated.');
			return;
		}
		if (isset($task['data']['error']))
			$error = $task['data']['error'];
		elseif (isset($task['data']['messages']))
			$error = $task['data']['messages'];
		else
			$error = '';
		EventLog::failure("Generating module '" . $module->moduleTitle . "' failed.", $error);
		if ($args['deleteOnError'])
			$module->delete();
		else
			$module->markFailed();
	}

	/**
	 * (Re)generating a config module succeeded. Update db entry.
	 *
	 * @param array $args contains 'moduleid' and optionally 'deleteOnError' and 'tmpTgz'
	 */
	public static function generateSucceeded($args)
	{
		if (!isset($args['moduleid']) || !is_numeric($args['moduleid'])) {
			EventLog::warning('Ignoring generateSucceeded event as it has no moduleid assigned.');
			return;
		}
		$module = self::get($args['moduleid']);
		if ($module === false) {
			EventLog::warning('generateSucceeded callback for module id ' . $args['moduleid'] . ', but no instance could be generated.');
			return;
		}
		if (isset($args['tmpTgz']))
			$module->markUpdated($args['tmpTgz']);
		else
			$module->markUpdated(false);
	}

}
