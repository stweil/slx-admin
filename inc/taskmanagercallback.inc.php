<?php

/**
 * Contains all callbacks for detached taskmanager tasks.
 */
class TaskmanagerCallback
{

	/**
	 * Add a callback for given task id. This is the only exception in this class,
	 * as this is not a callback, but a function to define one :)
	 *
	 * @param string|array $task Task or Task ID to define callback for
	 * @param string $callback name of callback function, must be a static method in this class
	 */
	public static function addCallback($task, $callback, $args = NULL)
	{
		if (!call_user_func_array('method_exists', array('TaskmanagerCallback', $callback))) {
			EventLog::warning("addCallback: Invalid callback function: $callback");
			return;
		}
		if (is_array($task) && isset($task['id']))
			$task = $task['id'];
		if (!is_string($task)) {
			EventLog::warning("addCallback: Not a valid task id: $task", print_r(debug_backtrace(), true));
			return;
		}
		$data = array(
			'task' => $task,
			'callback' => $callback,
		);
		if (is_null($args)) {
			$data['args'] = '';
		} else {
			$data['args'] = serialize($args);
		}
		if (Database::exec("INSERT INTO callback (taskid, dateline, cbfunction, args)"
				. " VALUES (:task, UNIX_TIMESTAMP(), :callback, :args)", $data, true) !== false) {
			return;
		}
		// Most likely the args column is missing - try to add it on-the-fly so the update routine can properly
		// use it (confmod updates - otherwise the status of modules is not updated properly)
		Database::exec("ALTER TABLE `callback` ADD `args` TEXT NOT NULL DEFAULT ''", array(), true);
		Database::exec("INSERT INTO callback (taskid, dateline, cbfunction, args)"
			. " VALUES (:task, UNIX_TIMESTAMP(), :callback, :args)", $data);
	}

	/**
	 * Get all pending callbacks from the callback table.
	 *
	 * @return array list of array(taskid => list of callbacks)
	 */
	public static function getPendingCallbacks()
	{
		$res = Database::simpleQuery("SELECT taskid, cbfunction, args FROM callback", array(), true);
		if ($res === false)
			return array();
		$retval = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$retval[$row['taskid']][] = $row;
		}
		return $retval;
	}

	/**
	 * Handle the given callback. Will delete the entry from the callback
	 * table if appropriate.
	 * 
	 * @param array $callback entry from the callback table (cbfunction + taskid + args)
	 * @param array $status status of the task as returned by the taskmanager. If NULL it will be queried.
	 */
	public static function handleCallback($callback, $status = NULL)
	{
		if (is_null($status))
			$status = Taskmanager::status($callback['taskid']);
		if ($status === false) // No reply from Taskmanager, retry later
			return;
		if (Taskmanager::isFailed($status) || Taskmanager::isFinished($status)) {
			$del = Database::exec("DELETE FROM callback WHERE taskid = :task AND cbfunction = :cb LIMIT 1", array('task' => $callback['taskid'], 'cb' => $callback['cbfunction']));
			if ($del === 0) { // No entry deleted, so someone else must have deleted it - race condition, do nothing
				return;
			}
		}
		if (Taskmanager::isFinished($status)) {
			$func = array('TaskmanagerCallback', preg_replace('/\W/', '', $callback['cbfunction']));
			if (!call_user_func_array('method_exists', $func)) {
				Eventlog::warning("handleCallback: Callback {$callback['cbfunction']} doesn't exist.");
			} else {
				if (empty($callback['args']))
					call_user_func($func, $status);
				else
					call_user_func($func, $status, unserialize($callback['args']));
			}
		}
	}

	// ####################################################################

	/**
	 * Result of trying to (re)launch ldadp.
	 */
	public static function ldadpStartup($task)
	{
		if (Taskmanager::isFailed($task)) {
			if (!isset($task['data']['messages'])) {
				$task['data']['messages'] = '';
			}
			EventLog::warning("Could not start/stop LDAP-AD-Proxy instances", $task['data']['messages']);
		}
	}

	/**
	 * Result of restoring the server configuration
	 */
	public static function dbRestored($task)
	{
		if (!Taskmanager::isFailed($task)) {
			EventLog::info('Configuration backup restored.');
		}
	}
	
	public static function adConfigCreate($task)
	{
		if (Taskmanager::isFailed($task))
			EventLog::warning("Could not generate Active Directory configuration", $task['data']['error']);
	}
	
	/**
	 * Generating a config module has finished.
	 *
	 * @param array $task task obj
	 * @param array $args has keys 'moduleid' and optionally 'deleteOnError' and 'tmpTgz'
	 */
	public static function cbConfModCreated($task, $args)
	{
		$mod = Module::get('sysconfig');
		if ($mod === false)
			return;
		$mod->activate(1, false);
		if (Taskmanager::isFailed($task)) {
			ConfigModule::generateFailed($task, $args);
		} else {
			ConfigModule::generateSucceeded($args);
		}
	}
	
	/**
	 * Generating a config.tgz has finished.
	 *
	 * @param array $task task obj
	 * @param array $args has keys 'configid' and optionally 'deleteOnError'
	 */
	public static function cbConfTgzCreated($task, $args)
	{
		$mod = Module::get('sysconfig');
		if ($mod === false)
			return;
		$mod->activate(1, false);
		if (Taskmanager::isFailed($task)) {
			ConfigTgz::generateFailed($task, $args);
		} else {
			ConfigTgz::generateSucceeded($args);
		}
	}
	
	public static function manualMount($task, $args)
	{
		if (!isset($task['data']['exitCode']))
			return;
		if ($task['data']['exitCode'] == 0) {
			// Success - store configuration
			Property::setVmStoreConfig($args);
			return;
		}
		// If code is 99 then the script failed to even unmount -- don't change anything
		if ($task['data']['exitCode'] != 99) {
			// Manual mount failed with non-taskmanager related error - reset storage type to reflect situation
			$data = Property::getVmStoreConfig();
			if (isset($data['storetype'])) {
				unset($data['storetype']);
				Property::setVmStoreConfig($data);
			}
			return;
		}
	}

	public static function uploadimg($task)
	{
		//$string=var_export($task, true);
		//file_put_contents('teste.txt',$string);
		if (Taskmanager::isFailed($task)){
			EventLog::warning("ApiUpload failed: ", $task['data']['messages']);
		}
		$ret = Database::exec("UPDATE upload SET is_ready = :ready," .
			" path = :path WHERE token = :token", array(
			"ready"=>true,
			"path"=>$task['data']['path'],
			"token"=>$task['data']['token']
		));
	}


}
