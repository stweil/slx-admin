<?php

/**
 * Interface to the external task manager.
 */
class Taskmanager
{

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
		if ($reply === false || !is_array($reply) || !isset($reply['id'])
				|| (isset($reply['statusCode']) && $reply['statusCode'] === NO_SUCH_TASK)) {
			self::addErrorMessage(false);
			return false;
		}
		return $reply;
	}

	public static function status($taskId)
	{
		self::init();
		$seq = (string) mt_rand();
		$message = "$seq, status, $taskId";
		$sent = socket_send(self::$sock, $message, strlen($message), 0);
		$reply = self::readReply($seq);
		if (!is_array($reply))
			return false;
		return $reply;
	}

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
			usleep(150000);
		}
		if ($done)
			self::release($task);
		return $status;
	}

	public static function isFailed($task)
	{
		if (!is_array($task) || !isset($task['statusCode']) || !isset($task['id']))
			return true;
		if ($task['statusCode'] !== TASK_WAITING && $task['statusCode'] !== TASK_PROCESSING && $task['statusCode'] !== TASK_FINISHED)
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

	public static function release($taskId)
	{
		self::init();
		$seq = (string) mt_rand();
		$message = "$seq, release, $taskId";
		socket_send(self::$sock, $message, strlen($message), 0);
	}

	/**
	 * 
	 * @param type $seq
	 * @return mixed the decoded json data for that message as an array, or null on error
	 */
	private static function readReply($seq)
	{
		$tries = 0;
		while (($bytes = socket_recvfrom(self::$sock, $buf, 90000, 0, $bla1, $bla2)) !== false) {
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
