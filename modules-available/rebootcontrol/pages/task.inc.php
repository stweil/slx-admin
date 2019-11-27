<?php

class SubPage
{

	public static function doPreprocess()
	{

	}

	public static function doRender()
	{
		$show = Request::get('what', 'tasklist', 'string');
		if ($show === 'tasklist') {
			self::showTaskList();
		} elseif ($show === 'task') {
			self::showTask();
		}
	}

	private static function showTask()
	{
		$taskid = Request::get("taskid", Request::REQUIRED, 'string');
		$task = Taskmanager::status($taskid);

		if (!Taskmanager::isTask($task) || !isset($task['data'])) {
			Message::addError('no-such-task', $taskid);
			return;
		}

		$td =& $task['data'];
		$type = Request::get('type', false, 'string');
		if ($type === false) {
			// Try to guess
			if (isset($td['locationId']) || isset($td['clients'])) {
				$type = 'reboot';
			} elseif (isset($td['result'])) {
				$type = 'exec';
			}
		}
		if ($type === 'reboot') {
			$data = [
				'taskId' => $task['id'],
				'locationId' => $td['locationId'],
				'locationName' => Location::getName($td['locationId']),
			];
			$uuids = array_map(function ($entry) {
				return $entry['machineuuid'];
			}, $td['clients']);
			$data['clients'] = RebootQueries::getMachinesByUuid($uuids);
			Render::addTemplate('status-reboot', $data);
		} elseif ($type === 'exec') {
			$data = [
				'taskId' => $task['id'],
			];
			Render::addTemplate('status-exec', $data);
		} elseif ($type === 'checkhost') {
			$ip = array_key_first($td['result']);
			$data = [
				'taskId' => $task['id'],
				'host' => $ip,
			];
			Render::addTemplate('status-checkconnection', $data);
		} else {
			Message::addError('unknown-task-type');
		}
	}

	private static function showTaskList()
	{
		// Append list of active reboot/shutdown tasks
		$allowedLocs = User::getAllowedLocations("*");
		$active = RebootControl::getActiveTasks($allowedLocs);
		if (empty($active)) {
			Message::addInfo('no-current-tasks');
		} else {
			foreach ($active as &$entry) {
				$entry['locationName'] = Location::getName($entry['locationId']);
			}
			unset($entry);
			Render::addTemplate('task-list', ['list' => $active]);
		}
	}

	public static function doAjax()
	{

	}

}


// Remove when we require >= 7.3.0
if (!function_exists('array_key_first')) {
	function array_key_first(array $arr) {
		foreach($arr as $key => $unused) {
			return $key;
		}
		return NULL;
	}
}
