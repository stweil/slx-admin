<?php

/**
 * Interface to the external task manager.
 */
class Taskmanager
{

	/**
	 * UDP socket used for communication with the task manager
	 * @var resource
	 */
	private static $sock = false;

	private static function init()
	{
		if (self::$sock !== false)
			return;
		self::$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		socket_set_option(self::$sock, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 0, 'usec' => 300000));
		socket_set_option(self::$sock, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 0, 'usec' => 200000));
		socket_connect(self::$sock, '127.0.0.1', 9215);
	}

	/**
	 * Start a task via the task manager.
	 *
	 * @param string $task name of task to start
	 * @param array $data data to pass to the task. the structure depends on the task.
	 * @param boolean $async if true, the function will not wait for the reply of the taskmanager, which means
	 * 		the return value is just true (and you won't know if the task could acutally be started)
	 * @return array struct representing the task status, or result of submit, false on communication error
	 */
	public static function submit($task, $data = false, $async = false)
	{
		self::init();
		$seq = (string) mt_rand();
		if (empty($data)) {
			$data = '{}';
		} else {
			$data = json_encode($data);
		}
		$message = "$seq, $task, $data";
		$sent = socket_send(self::$sock, $message, strlen($message), 0);
		if ($sent != strlen($message)) {
			self::addErrorMessage(false);
			return false;
		}
		if ($async)
			return true;
		$reply = self::readReply($seq);
		if ($reply === false || !is_array($reply) || !isset($reply['id']) || (isset($reply['statusCode']) && $reply['statusCode'] === NO_SUCH_TASK)) {
			self::addErrorMessage($reply);
			return false;
		}
		return $reply;
	}

	/**
	 * Query status of given task.
	 *
	 * @param mixed $task task id or task struct
	 * @return array status of task as array, or false on communication error
	 */
	public static function status($task)
	{
		if (is_array($task) && isset($task['id'])) {
			$task = $task['id'];
		}
		if (!is_string($task))
			return false;
		self::init();
		$seq = (string) mt_rand();
		$message = "$seq, status, $task";
		$sent = socket_send(self::$sock, $message, strlen($message), 0);
		$reply = self::readReply($seq);
		if (!is_array($reply))
			return false;
		return $reply;
	}

	/**
	 * Checks whether the given task id corresponds to a known task in the taskmanager.
	 * Returns true iff the taskmanager is reachable and the status of the task
	 * is different from NO_SUCH_TASK.
	 *
	 * @param string $taskid a task id
	 * @return boolean true if taskid exists in taskmanager
	 */
	public static function isTask($taskid)
	{
		$task = self::status($taskid);
		return isset($task['statusCode']) && $task['statusCode'] !== NO_SUCH_TASK;
	}

	/**
	 * Wait for the given task's completion.
	 *
	 * @param type $task task to wait for
	 * @param int $timeout maximum time in ms to wait for completion of task
	 * @return array result/status of task, or false if it couldn't be queried
	 */
	public static function waitComplete($task, $timeout = 1500)
	{
		if (is_array($task) && isset($task['id'])) {
			if ($task['statusCode'] !== TASK_PROCESSING && $task['statusCode'] !== TASK_WAITING) {
				self::release($task['id']);
				return $task;
			}
			$task = $task['id'];
		}
		if (!is_string($task))
			return false;
		$done = false;
		for ($i = 0; $i < ($timeout / 150); ++$i) {
			$status = self::status($task);
			if (!isset($status['statusCode']))
				break;
			if ($status['statusCode'] !== TASK_PROCESSING && $status['statusCode'] !== TASK_WAITING) {
				$done = true;
				break;
			}
			usleep(100000);
		}
		if ($done)
			self::release($task);
		return $status;
	}

	/**
	 * Check whether the given task can be considered failed.
	 *
	 * @param array $task struct representing task, obtained by ::status
	 * @return boolean true if task failed, false if finished successfully or still waiting/running
	 */
	public static function isFailed($task)
	{
		if (!is_array($task) || !isset($task['statusCode']) || !isset($task['id']))
			return true;
		if ($task['statusCode'] !== TASK_WAITING && $task['statusCode'] !== TASK_PROCESSING && $task['statusCode'] !== TASK_FINISHED)
			return true;
		return false;
	}

	/**
	 * Check whether the given task is finished, i.e. either failed or succeeded,
	 * but is not running, still waiting for execution or simply unknown.
	 *
	 * @param array $task struct representing task, obtained by ::status
	 * @return boolean true if task failed or finished, false if waiting for execution or currently executing, no valid task, etc.
	 */
	public static function isFinished($task)
	{
		if (!is_array($task) || !isset($task['statusCode']) || !isset($task['id']))
			return false;
		if ($task['statusCode'] === TASK_ERROR || $task['statusCode'] === TASK_FINISHED)
			return true;
		return false;
	}

	public static function addErrorMessage($task)
	{
		static $failure = false;
		if ($task === false) {
			if (!$failure) {
				Message::addError('taskmanager-error');
				$failure = true;
			}
			return;
		}
		if (!isset($task['statusCode'])) {
			Message::addError('taskmanager-format');
			return;
		}
		if (isset($task['data']['error'])) {
			Message::addError('task-error', $task['statusCode'] . ' (' . $task['data']['error'] . ')');
			return;
		}
		Message::addError('task-error', $task['statusCode']);
	}

	/**
	 * Release a given task from the task manager, so it won't keep the result anymore in case it's finished running.
	 *
	 * @param string $task task to release. can either be its id, or a struct representing the task, as returned
	 * by ::submit() or ::status()
	 */
	public static function release($task)
	{
		if (is_array($task) && isset($task['id'])) {
			$task = $task['id'];
		}
		if (!is_string($task))
			return;
		self::init();
		$seq = (string) mt_rand();
		$message = "$seq, release, $task";
		socket_send(self::$sock, $message, strlen($message), 0);
	}

	/**
	 * Read reply from socket for given sequence number.
	 *
	 * @param string $seq
	 * @return mixed the decoded json data for that message as an array, or null on error
	 */
	private static function readReply($seq)
	{
		$tries = 0;
		while (($bytes = socket_recvfrom(self::$sock, $buf, 90000, 0, $bla1, $bla2)) !== false || socket_last_error() === 11) {
			$parts = explode(',', $buf, 2);
			if (count($parts) == 2 && $parts[0] == $seq) {
				return json_decode($parts[1], true);
			}
			if (++$tries > 10)
				return false;
		}
		//error_log(socket_strerror(socket_last_error(self::$sock)));
		return false;
	}

}

foreach (array('TASK_FINISHED', 'TASK_ERROR', 'TASK_WAITING', 'NO_SUCH_TASK', 'TASK_PROCESSING') as $i) {
	define($i, $i);
}
