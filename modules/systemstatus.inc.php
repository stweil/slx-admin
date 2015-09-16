<?php

class Page_SystemStatus extends Page
{

	private $rebootTask = false;

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}

		if (Request::post('action') === 'reboot') {
			if (Request::post('confirm') !== 'yep') {
				Message::addError('reboot-unconfirmed');
				Util::redirect('?do=SystemStatus');
			}
			$this->rebootTask = Taskmanager::submit('Reboot');
		}
	}

	protected function doRender()
	{
		$data = array();
		if (is_array($this->rebootTask) && isset($this->rebootTask['id'])) {
			$data['rebootTask'] = $this->rebootTask['id'];
		}
		Render::addScriptTop('custom');
		Render::addScriptBottom('circles.min');
		Render::addTemplate('systemstatus/_page', $data);
	}

	protected function doAjax()
	{
		User::load();

		if (!User::isLoggedIn())
			return;

		$action = 'ajax' . Request::any('action');
		if (method_exists($this, $action)) {
			$this->$action();
			Message::renderList();
		} else {
			echo "Action $action not known in " . get_class();
		}
	}
	
	protected function ajaxDmsdUsers()
	{
		$ret = Download::asStringPost('http://127.0.0.1:9080/status/fileserver', false, 2, $code);
		if ($code != 200) {
			Header('HTTP/1.1 502 Internal Server Error');
			die('Internal Server Wurst');
		}
		$data = @json_decode($ret, true);
		if (is_array($data)) {
			$ret = 'Uploads: ' . $data['activeUploads'] . ', Downloads: ' . $data['activeDownloads'];
		} else {
			$ret = '???';
		}
		die($ret);
	}

	protected function ajaxDiskStat()
	{
		$task = Taskmanager::submit('DiskStat');
		if ($task === false)
			return;
		$task = Taskmanager::waitComplete($task, 3000);

		if (!isset($task['data']['list']) || empty($task['data']['list'])) {
			Taskmanager::addErrorMessage($task);
			return;
		}
		$store = Property::getVmStoreUrl();
		$storeUsage = false;
		$systemUsage = false;
		if ($store !== false) {
			if ($store === '<local>')
				$storePoint = '/';
			else
				$storePoint = CONFIG_VMSTORE_DIR;
			// Determine free space
			foreach ($task['data']['list'] as $entry) {
				if ($entry['mountPoint'] === $storePoint) {
					$storeUsage = array(
						'percent' => $entry['usedPercent'],
						'size' => Util::readableFileSize($entry['sizeKb'] * 1024),
						'free' => Util::readableFileSize($entry['freeKb'] * 1024),
						'color' => $this->usageColor($entry['usedPercent'])
					);
				}
				if ($entry['mountPoint'] === '/') {
					$systemUsage = array(
						'percent' => $entry['usedPercent'],
						'size' => Util::readableFileSize($entry['sizeKb'] * 1024),
						'free' => Util::readableFileSize($entry['freeKb'] * 1024),
						'color' => $this->usageColor($entry['usedPercent'])
					);
				}
			}
			// Determine if proper vm store is being used
			if ($store === '<local>')
				$storeError = false;
			else
				$storeError = 'VM-Store nicht eingebunden! Erwartet: ' . $store;
			foreach ($task['data']['list'] as $entry) {
				if ($entry['mountPoint'] === CONFIG_VMSTORE_DIR && $store !== $entry['fileSystem']) {
					$storeError = 'Falscher VM-Store eingebunden! Erwartet: ' . $store . ' vorgefunden: ' . $entry['fileSystem'];
					break;
				} elseif ($entry['mountPoint'] === CONFIG_VMSTORE_DIR) {
					$storeError = false;
				}
			}
		} else {
			$storeError = 'Storage not configured';
		}
		echo Render::parse('systemstatus/diskstat', array(
			'store' => $storeUsage,
			'system' => $systemUsage,
			'storeError' => $storeError
		));
	}

	protected function ajaxAddressList()
	{
		$task = Taskmanager::submit('LocalAddressesList');
		if ($task === false)
			return;
		$task = Taskmanager::waitComplete($task, 3000);

		if (!isset($task['data']['addresses']) || empty($task['data']['addresses'])) {
			Taskmanager::addErrorMessage($task);
			return;
		}

		$sort = array();
		$primary = Property::getServerIp();
		foreach ($task['data']['addresses'] as &$addr) {
			$sort[] = $addr['type'] . $addr['ip'];
			if ($addr['ip'] === $primary)
				$addr['primary'] = true;
		}
		array_multisort($sort, SORT_STRING, $task['data']['addresses']);
		echo Render::parse('systemstatus/addresses', array(
			'addresses' => $task['data']['addresses']
		));
	}

	protected function ajaxSystemInfo()
	{
		$cpuInfo = file_get_contents('/proc/cpuinfo');
		$memInfo = file_get_contents('/proc/meminfo');
		$stat = file_get_contents('/proc/stat');
		$uptime = file_get_contents('/proc/uptime');
		$cpuCount = preg_match_all('/\bprocessor\s/', $cpuInfo, $out);
		//$cpuCount = count($out);
		$data = array(
			'cpuCount' => $cpuCount,
			'memTotal' => '???',
			'memFree' => '???',
			'swapTotal' => '???',
			'swapUsed' => '???',
			'uptime' => '???'
		);
		if (preg_match('/^(\d+)\D/', $uptime, $out)) {
			$data['uptime'] = floor($out[1] / 86400) . ' ' . Dictionary::translate('lang_days') . ', ' . floor(($out[1] % 86400) / 3600) . ' ' . Dictionary::translate('lang_hours'); // TODO: i18n
		}
		if (preg_match('/\bMemTotal:\s+(\d+)\s.*\bMemFree:\s+(\d+)\s.*\bBuffers:\s+(\d+)\s.*\bCached:\s+(\d+)\s.*\bSwapTotal:\s+(\d+)\s.*\bSwapFree:\s+(\d+)\s/s', $memInfo, $out)) {
			$data['memTotal'] = Util::readableFileSize($out[1] * 1024);
			$data['memFree'] = Util::readableFileSize(($out[2] + $out[3] + $out[4]) * 1024);
			$data['memPercent'] = 100 - round((($out[2] + $out[3] + $out[4]) / $out[1]) * 100);
			$data['swapTotal'] = Util::readableFileSize($out[5] * 1024);
			$data['swapUsed'] = Util::readableFileSize(($out[5] - $out[6]) * 1024);
			$data['swapPercent'] = 100 - round(($out[6] / $out[5]) * 100);
			$data['swapWarning'] = ($data['swapPercent'] > 50 || ($out[5] - $out[6]) > 100000);
		}
		if (preg_match('/\bcpu\s+(?<user>\d+)\s+(?<nice>\d+)\s+(?<system>\d+)\s+(?<idle>\d+)\s+(?<iowait>\d+)\s+(?<irq>\d+)\s+(?<softirq>\d+)(\s|$)/', $stat, $out)) {
			$total = $out['user'] + $out['nice'] + $out['system'] + $out['idle'] + $out['iowait'] + $out['irq'] + $out['softirq'];
			$data['cpuLoad'] = 100 - round(($out['idle'] / $total) * 100);
			$data['cpuSystem'] = round((($out['iowait'] + $out['irq'] + $out['softirq']) / $total) * 100);
			$data['cpuLoadOk'] = true;
		}
		echo Render::parse('systemstatus/systeminfo', $data);
	}

	protected function ajaxServices()
	{
		$data = array();

		$taskId = Trigger::ldadp();
		if ($taskId === false)
			return;
		$status = Taskmanager::waitComplete($taskId, 10000);

		if (Taskmanager::isFailed($status)) {
			if (isset($status['data']['messages']))
				$data['ldadpError'] = $status['data']['messages'];
			else
				$data['ldadpError'] = 'Taskmanager error';
		}
		// TODO: Dozentenmodul, tftp, ...

		echo Render::parse('systemstatus/services', $data);
	}

	protected function ajaxDmsdLog()
	{
		$fh = @fopen('/var/log/dmsd.log', 'r');
		if ($fh === false) {
			echo 'Error opening log file';
			return;
		}
		fseek($fh, -6000, SEEK_END);
		$data = fread($fh, 6000);
		@fclose($fh);
		if ($data === false) {
			echo 'Error reading from log file';
			return;
		}
		echo '<pre>', htmlspecialchars(substr($data, strpos($data, "\n") + 1)), '</pre>';
	}

	protected function ajaxNetstat()
	{
		$taskId = Taskmanager::submit('Netstat');
		if ($taskId === false)
			return;
		$status = Taskmanager::waitComplete($taskId, 3500);

		if (isset($status['data']['messages']))
			$data = $status['data']['messages'];
		else
			$data = 'Taskmanager error';

		echo '<pre>', htmlspecialchars($data), '</pre>';
	}

	protected function ajaxPsList()
	{
		$taskId = Taskmanager::submit('PsList');
		if ($taskId === false)
			return;
		$status = Taskmanager::waitComplete($taskId, 3500);

		if (isset($status['data']['messages']))
			$data = $status['data']['messages'];
		else
			$data = 'Taskmanager error';

		echo '<pre>', htmlspecialchars($data), '</pre>';
	}

	private function usageColor($percent)
	{
		if ($percent <= 50) {
			$r = $b = $percent / 3;
			$g = (100 - $percent * (50 / 80));
		} elseif ($percent <= 70) {
			$r = 55 + ($percent - 50) * (30 / 20);
			$g = 60;
			$b = 0;
		} else {
			$r = ($percent - 70) / 3 + 90;
			$g = (100 - $percent) * (60 / 30);
			$b = 0;
		}
		$r = dechex(round($r * 2.55));
		$g = dechex(round($g * 2.55));
		$b = dechex(round($b * 2.55));
		return sprintf("%02s%02s%02s", $r, $g, $b);
	}

}
