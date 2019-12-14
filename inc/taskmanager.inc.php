<?php

/**
 * Interface to the external task manager.
 */
class Taskmanager
{

	const NO_SUCH_TASK = 'NO_SUCH_TASK';
	const TASK_FINISHED = 'TASK_FINISHED';
	const TASK_ERROR = 'TASK_ERROR';
	const TASK_WAITING = 'TASK_WAITING';
	const NO_SUCH_INSTANCE = 'NO_SUCH_INSTANCE';
	const TASK_PROCESSING = 'TASK_PROCESSING';

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
	 * 		the return value is just true (and you won't know if the task could actually be started)
	 * @return array|false struct representing the task status (as a result of submit); false on communication error
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
		if ($reply === false || !is_array($reply) || !isset($reply['id']) || (isset($reply['statusCode']) && $reply['statusCode'] === Taskmanager::NO_SUCH_TASK)) {
			self::addErrorMessage($reply);
			return false;
		}
		return $reply;
	}

	/**
	 * Query status of given task.
	 *
	 * @param mixed $task task id or task struct
	 * @return array|false status of task as array, or false on communication error
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
		$message = "$seq,     status, $task";
		socket_send(self::$sock, $message, strlen($message), 0);
		$reply = self::readReply($seq);
		if (!is_array($reply))
			return false;
		return $reply;
	}

	/**
	 * Checks whether the given task id corresponds to a known task in the taskmanager.
	 * Returns true iff the taskmanager is reachable and the status of the task
	 * is different from Taskmanager::NO_SUCH_INSTANCE/_TASK.
	 * If you pass an array it is assumed that it was already queried and is evaluated
	 * directly.
	 *
	 * @param string|array $taskid a task id or a task array returned by ::status or ::submit
	 * @return boolean true if taskid exists in taskmanager
	 */
	public static function isTask($task)
	{
		if ($task === false)
			return false;
		if (is_string($task)) {
			$task = self::status($task);
		}
		return isset($task['statusCode']) && $task['statusCode'] !== Taskmanager::NO_SUCH_INSTANCE
			&& $task['statusCode'] !== Taskmanager::NO_SUCH_TASK;
	}

	/**
	 * Wait for the given task's completion.
	 *
	 * @param string|array $task task to wait for
	 * @param int $timeout maximum time in ms to wait for completion of task
	 * @return array|false result/status of task, or false if it couldn't be queried
	 */
	public static function waitComplete($task, $timeout = 2500)
	{
		if (is_array($task) && isset($task['id'])) {
			if ($task['statusCode'] !== Taskmanager::TASK_PROCESSING && $task['statusCode'] !== Taskmanager::TASK_WAITING) {
				self::release($task['id']);
				return $task;
			}
			$task = $task['id'];
		}
		if (!is_string($task))
			return false;
		$done = false;
		$deadline = microtime(true) + $timeout / 1000;
		do {
			$status = self::status($task);
			if (!isset($status['statusCode']))
				break;
			if ($status['statusCode'] !== Taskmanager::TASK_PROCESSING && $status['statusCode'] !== Taskmanager::TASK_WAITING) {
				$done = true;
				break;
			}
			usleep(100000);
		} while (microtime(true) < $deadline);
		if ($done) { // For now we do this unconditionally, but maybe we want to keep them longer some time?
			self::release($task);
		}
		return $status;
	}

	/**
	 * Check whether the given task can be considered failed. This
	 * includes that the task id is invalid, etc.
	 *
	 * @param array|false $task struct representing task, obtained by ::status
	 * @return boolean true if task failed, false if finished successfully or still waiting/running
	 */
	public static function isFailed($task)
	{
		if (!is_array($task) || !isset($task['statusCode']) || !isset($task['id']))
			return true;
		if ($task['statusCode'] !== Taskmanager::TASK_WAITING && $task['statusCode'] !== Taskmanager::TASK_PROCESSING && $task['statusCode'] !== Taskmanager::TASK_FINISHED)
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
		if ($task['statusCode'] !== Taskmanager::TASK_WAITING && $task['statusCode'] !== Taskmanager::TASK_PROCESSING)
			return true;
		return false;
	}

	/**
	 * Check whether the given task is running, that is either waiting for execution
	 * or currently executing.
	 *
	 * @param array $task struct representing task, obtained by ::status
	 * @return boolean true if task is waiting or executing, false if waiting for execution or currently executing, no valid task, etc.
	 */
	public static function isRunning($task)
	{
		if (!is_array($task) || !isset($task['statusCode']) || !isset($task['id']))
			return false;
		if ($task['statusCode'] === Taskmanager::TASK_WAITING || $task['statusCode'] === Taskmanager::TASK_PROCESSING)
			return true;
		return false;
	}

	public static function addErrorMessage($task)
	{
		static $failure = false;
		if ($task === false) {
			if (!$failure) {
				Message::addError('main.taskmanager-error');
				$failure = true;
			}
			return;
		}
		if (!isset($task['statusCode'])) {
			Message::addError('main.taskmanager-format');
			return;
		}
		if (isset($task['data']['error'])) {
			Message::addError('main.task-error', $task['statusCode'] . ' (' . $task['data']['error'] . ')');
			return;
		}
		Message::addError('main.task-error', $task['statusCode']);
	}

	/**
	 * Release a given task from the task manager, so it won't keep the result anymore in case it's finished running.
	 *
	 * @param string|array $task task to release. can either be its id, or a struct representing the task, as returned
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
		while (($bytes = @socket_recvfrom(self::$sock, $buf, 90000, 0, $bla1, $bla2)) !== false || socket_last_error() === 11) {
			$parts = explode(',', $buf, 2);
			// Do we have compressed data?
			if (substr($parts[0], 0, 3) === '+z:') {
				$parts[0] = substr($parts[0], 3);
				$gz = true;
			} else {
				$gz = false;
			}
			// See if it's our message
			if (count($parts) === 2 && $parts[0] === $seq) {
				if ($gz) {
					$parts[1] = gzinflate($parts[1]);
					if ($parts[1] === false) {
						error_log('Taskmanager: Invalid deflate data received');
						continue;
					}
				}
				return json_decode($parts[1], true);
			}
			if (++$tries > 10)
				return false;
		}
		error_log('Reading taskmanager reply failed, socket error ' . socket_last_error());
		return false;
	}

}
