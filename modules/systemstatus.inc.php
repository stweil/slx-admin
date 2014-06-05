<?php

class Page_SystemStatus extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Util::redirect('?do=Main');
		}
	}

	protected function doRender()
	{
		Render::addScriptTop('custom');
		Render::addScriptBottom('circles.min');
		Render::addTemplate('systemstatus/_page');
	}

	protected function doAjax()
	{
		User::load();

		if (!User::isLoggedIn())
			return;

		$action = 'ajax' . Request::any('action');
		if (method_exists($this, $action))
			$this->$action();
		else
			echo "Action $action not known in " . get_class();
	}

	protected function ajaxDiskStat()
	{
		$task = Taskmanager::submit('DiskStat');
		$task = Taskmanager::waitComplete($task);

		if (!isset($task['data']['list']) || empty($task['data']['list'])) {
			Taskmanager::addErrorMessage($task);
			Message::renderList();
			return;
		}
		$store = Property::getVmStoreUrl();
		$storeUsage = false;
		$systemUsage = false;
		if ($store !== false) {
			if ($store === '<local>')
				$storePoint = '/';
			else
				$storePoint = '/srv/openslx/nfs';
			foreach ($task['data']['list'] as $entry) {
				if ($entry['mountPoint'] === $storePoint)
					$storeUsage = array(
						'percent' => $entry['usedPercent'],
						'size' => Util::readableFileSize ($entry['sizeKb'] * 1024),
						'color' => dechex(round(($entry['usedPercent'] / 100) * 15)) . dechex(round(((100 - $entry['usedPercent']) / 100) * 15)) . '4'
					);
				if ($entry['mountPoint'] === '/')
					$systemUsage = array(
						'percent' => $entry['usedPercent'],
						'size' => Util::readableFileSize ($entry['sizeKb'] * 1024),
						'color' => dechex(round(($entry['usedPercent'] / 100) * 15)) . dechex(round(((100 - $entry['usedPercent']) / 100) * 15)) . '4'
					);
			}
		}
		echo Render::parse('systemstatus/diskstat', array(
			'store' => $storeUsage,
			'system' => $systemUsage
		));
	}

}
