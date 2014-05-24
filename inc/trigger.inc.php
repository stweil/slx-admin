<?php

/**
 * This is one giant class containing various functions that will generate
 * required config files, daemon instances and more, mostly through the Taskmanager.
 * Most function *should* only actually do something if it is required to do so.
 * eg. a "launchSomething" function should only launch something if it isn't already
 * running. Checking if something is running can happen in that very function, or in
 * a task that the function is calling.
 */
class Trigger
{
	
	/**
	 * Compile iPXE pxelinux menu. Needs to be done whenever the server's IP
	 * address changes.
	 * 
	 * @param boolean $force force recompilation even if it seems up to date
	 * @return boolean|string true if already up to date, false if launching task failed, task-id otherwise
	 */
	public static function ipxe($force = false)
	{
		if (!$force && Property::getIPxeIp() === Property::getServerIp())
			return true; // Nothing to do
		$last = Property::getIPxeTaskId();
		if ($last !== false) {
			$status = Taskmanager::status($last);
			if (isset($status['statusCode']) && ($status['statusCode'] === TASK_WAITING || $status['statusCode'] === TASK_PROCESSING))
				return false; // Already compiling
		}
		$data = Property::getBootMenu();
		$data['ip'] = Property::getServerIp();
		$task = Taskmanager::submit('CompileIPxe', $data);
		if (!isset($task['id']))
			return false;
		Property::setIPxeTaskId($task['id']);
		return $task['id'];
	}

}