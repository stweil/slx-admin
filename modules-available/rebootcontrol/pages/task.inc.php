<?php

class SubPage
{

	public static function doPreprocess()
	{

	}

	public static function doRender()
	{
		$xxx = Request::get('tasks');
		if (is_array($xxx)) {
			$data = array_map(function($item) { return ['id' => $item]; }, $xxx);
			Render::addTemplate('status-wol', ['tasks' => $data]);
			return;
		}
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
		$type = Request::get('type', false, 'string');
		if ($type === 'checkhost') {
			// Override
			$task = Taskmanager::status($taskid);
			if (!Taskmanager::isTask($task) || !isset($task['data'])) {
				Message::addError('no-such-task', $taskid);
				return;
			}
			$td =& $task['data'];
			$ip = array_key_first($td['result']);
			$data = [
				'taskId' => $task['id'],
				'host' => $ip,
			];
			Render::addTemplate('status-checkconnection', $data);
			return;
		}
		if ($type !== false) {
			Message::addError('unknown-task-type');
		}

		$job = RebootControl::getActiveTasks(null, $taskid);
		if ($job === false) {
			Message::addError('no-such-task', $taskid);
			return;
		}
		if (isset($job['type'])) {
			$type = $job['type'];
		}
		if ($type === RebootControl::TASK_EXEC) {
			$template = $perm = 'exec';
		} elseif ($type === RebootControl::TASK_REBOOTCTL) {
			$template = 'reboot';
			if ($job['action'] === RebootControl::SHUTDOWN) {
				$perm = 'shutdown';
			} else {
				$perm = 'reboot';
			}
		} elseif ($type == RebootControl::TASK_WOL) {
			$template = $perm = 'wol';
		} else {
			Message::addError('unknown-task-type', $type);
			return;
		}
		if (!empty($job['locations'])) {
			$allowedLocs = User::getAllowedLocations("action.$perm");
			if (!in_array(0, $allowedLocs) && array_diff($job['locations'], $allowedLocs) !== []) {
				Message::addError('main.no-permission');
				return;
			}
			self::expandLocationIds($job['locations']);
		}

		// Output
		if ($type === RebootControl::TASK_REBOOTCTL) {
			$job['clients'] = RebootQueries::getMachinesByUuid(ArrayUtil::flattenByKey($job['clients'], 'machineuuid'));
		} elseif ($type === RebootControl::TASK_EXEC) {
			$details = RebootQueries::getMachinesByUuid(ArrayUtil::flattenByKey($job['clients'], 'machineuuid'), true);
			foreach ($job['clients'] as &$client) {
				if (isset($client['machineuuid']) && isset($details[$client['machineuuid']])) {
					$client += $details[$client['machineuuid']];
				}
			}
		} elseif ($type === RebootControl::TASK_WOL) {
			// Nothing (yet)
		} else {
			Util::traceError('oopsie');
		}
		Render::addTemplate('status-' . $template, $job);
	}

	private static function showTaskList()
	{
		Render::addTemplate('task-header');
		// Append list of active reboot/shutdown tasks
		$allowedLocs = User::getAllowedLocations("action.*");
		$active = RebootControl::getActiveTasks($allowedLocs);
		if (empty($active)) {
			Message::addInfo('no-current-tasks');
		} else {
			foreach ($active as &$entry) {
				self::expandLocationIds($entry['locations']);
				if (isset($entry['clients'])) {
					$entry['clients'] = count($entry['clients']);
				}
			}
			unset($entry);
			Render::addTemplate('task-list', ['list' => $active]);
		}
	}

	private static function expandLocationIds(&$lids)
	{
		foreach ($lids as &$locid) {
			if ($locid === 0) {
				$name = '-';
			} else {
				$name = Location::getName($locid);
			}
			$locid = ['id' => $locid, 'name' => $name];
		}
		$lids = array_values($lids);
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
