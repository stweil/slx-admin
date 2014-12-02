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
	public static function addCallback($task, $callback)
	{
		if (!call_user_func_array('method_exists', array('TaskmanagerCallback', $callback))) {
			EventLog::warning("addCallback: Invalid callback function: $callback");
			return;
		}
		if (is_array($task) && isset($task['id']))
			$task = $task['id'];
		if (!is_string($task)) {
			EventLog::warning("addCallback: Not a valid task id: $task");
			return;
		}
		Database::exec("INSERT INTO callback (taskid, dateline, cbfunction) VALUES (:task, UNIX_TIMESTAMP(), :callback)", array(
			'task' => $task,
			'callback' => $callback
		));
	}

	/**
	 * Get all pending callbacks from the callback table.
	 *
	 * @return array list of array(taskid => list of callbacks)
	 */
	public static function getPendingCallbacks()
	{
		$retval = array();
		$res = Database::simpleQuery("SELECT taskid, cbfunction FROM callback");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$retval[$row['taskid']][] = $row;
		}
		return $retval;
	}

	/**
	 * Handle the given callback. Will delete the entry from the callback
	 * table if appropriate.
	 * 
	 * @param array $callback entry from the callback table (cbfunction + taskid)
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
			if ($del === 0) // No entry deleted, so someone else must have deleted it - race condition, do nothing
				return;
		}
		if (Taskmanager::isFinished($status)) {
			$func = array('TaskmanagerCallback', preg_replace('/\W/', '', $callback['cbfunction']));
			if (!call_user_func_array('method_exists', $func)) {
				Eventlog::warning("handleCallback: Callback {$callback['cbfunction']} doesn't exist.");
			} else {
				call_user_func($func, $status);
			}
		}
	}

	// ####################################################################

	/**
	 * Result of trying to (re)launch ldadp.
	 */
	public static function ldadpStartup($task)
	{
		if (Taskmanager::isFailed($task))
			EventLog::warning("Could not start/stop LDAP-AD-Proxy instances", $task['data']['messages']);
	}

	public static function dbRestored($task)
	{
		error_log("dbRestored.");
		if (Taskmanager::isFinished($task) && !Taskmanager::isFailed($task)) {
			error_log("LOGGING.");
			EventLog::info('Configuration backup restored.');
		}
	}

}
