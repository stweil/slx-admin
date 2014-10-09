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
	 * Result of trying to (re)launch ldadp.
	 */
	public static function ldadpStartup($task)
	{
		if (Taskmanager::isFailed($task))
			EventLog::warning("Could not start/stop LDAP-AD-Proxy instances", $task['data']['messages']);
	}

}
