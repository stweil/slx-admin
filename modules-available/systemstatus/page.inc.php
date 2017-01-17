<?php

class Page_SystemStatus extends Page
{

	private $rebootTask = false;

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
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
		Render::addTemplate('_page', $data);
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
			$data = array(
				'store' => $storeUsage,
				'system' => $systemUsage
			);
			// Determine if proper vm store is being used
			if ($store !== '<local>') {
				$data['storeMissing'] = $store;
			}
			foreach ($task['data']['list'] as $entry) {
				if ($entry['mountPoint'] !== CONFIG_VMSTORE_DIR)
					continue;
				if ($store !== $entry['fileSystem']) {
					$data['wrongStore'] = $entry['fileSystem'];
					break;
				}
				$data['storeMissing'] = false;
			}
		} else {
			$data['notConfigured'] = true;
		}
		echo Render::parse('diskstat', $data);
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
		echo Render::parse('addresses', array(
			'addresses' => $task['data']['addresses']
		));
	}
	
	private function sysInfo()
	{
		$data = array();
		$memInfo = file_get_contents('/proc/meminfo');
		$stat = file_get_contents('/proc/stat');
		preg_match_all('/\b(\w+):\s+(\d+)\s/s', $memInfo, $out, PREG_SET_ORDER);
		foreach ($out as $e) {
			$data[$e[1]] = $e[2];
		}
		if (preg_match('/\bcpu\s+(?<user>\d+)\s+(?<nice>\d+)\s+(?<system>\d+)\s+(?<idle>\d+)\s+(?<iowait>\d+)\s+(?<irq>\d+)\s+(?<softirq>\d+)(\s|$)/', $stat, $out)) {
			$data['CpuTotal'] = $out['user'] + $out['nice'] + $out['system'] + $out['idle'] + $out['iowait'] + $out['irq'] + $out['softirq'];
			$data['CpuIdle'] = $out['idle'] + $out['iowait'];
			$data['CpuSystem'] = $out['irq'] + $out['softirq'];
		}
		return $data;
	}

	protected function ajaxSystemInfo()
	{
		$cpuInfo = file_get_contents('/proc/cpuinfo');
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
			$data['uptime'] = floor($out[1] / 86400) . ' ' . Dictionary::translate('lang_days') . ', ' . floor(($out[1] % 86400) / 3600) . ' ' . Dictionary::translate('lang_hours');
		}
		$info = $this->sysInfo();
		if (isset($info['MemTotal']) && isset($info['MemFree']) && isset($info['SwapTotal'])) {
			$data['memTotal'] = Util::readableFileSize($info['MemTotal'] * 1024);
			$data['memFree'] = Util::readableFileSize(($info['MemFree'] + $info['Buffers'] + $info['Cached']) * 1024);
			$data['memPercent'] = 100 - round((($info['MemFree'] + $info['Buffers'] + $info['Cached']) / $info['MemTotal']) * 100);
			$data['swapTotal'] = Util::readableFileSize($info['SwapTotal'] * 1024);
			$data['swapUsed'] = Util::readableFileSize(($info['SwapTotal'] - $info['SwapFree']) * 1024);
			$data['swapPercent'] = 100 - round(($info['SwapFree'] / $info['SwapTotal']) * 100);
			$data['swapWarning'] = ($data['swapPercent'] > 50 || ($info['SwapTotal'] - $info['SwapFree']) > 200000);
		}
		if (isset($info['CpuIdle']) && isset($info['CpuSystem']) && isset($info['CpuTotal'])) {
			$data['cpuLoad'] = 100 - round(($info['CpuIdle'] / $info['CpuTotal']) * 100);
			$data['cpuSystem'] = round(($info['CpuSystem'] / $info['CpuTotal']) * 100);
			$data['cpuLoadOk'] = true;
			$data['CpuTotal'] = $info['CpuTotal'];
			$data['CpuIdle'] = $info['CpuIdle'];
		}
		echo Render::parse('systeminfo', $data);
	}
	
	protected function ajaxSysPoll()
	{
		$info = $this->sysInfo();
		$data = array(
			'CpuTotal' => $info['CpuTotal'],
			'CpuIdle' => $info['CpuIdle'],
			'MemPercent' => 100 - round((($info['MemFree'] + $info['Buffers'] + $info['Cached']) / $info['MemTotal']) * 100),
			'SwapPercent' => 100 - round(($info['SwapFree'] / $info['SwapTotal']) * 100)
		);
		Header('Content-Type: application/json; charset=utf-8');
		die(json_encode($data));
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

		echo Render::parse('services', $data);
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
		// If we could read less, try the .1 file too
		$amount = 6000 - strlen($data);
		if ($amount > 100) {
			$fh = @fopen('/var/log/dmsd.log.1', 'r');
			if ($fh !== false) {
				fseek($fh, -$amount, SEEK_END);
				$data = fread($fh, $amount) . $data;
				@fclose($fh);
			}
		}
		if (strlen($data) < 5990) {
			$start = 0;
		} else {
			$start = strpos($data, "\n") + 1;
		}
		echo '<pre>', htmlspecialchars(substr($data, $start), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '</pre>';
	}

	protected function ajaxLdadpLog()
	{
		$haveSysconfig = Module::isAvailable('sysconfig');
		$files = glob('/var/log/ldadp/*.log', GLOB_NOSORT);
		if ($files === false || empty($files)) echo('No logs found');
		$now = time();
		foreach ($files as $file) {
			$mod = filemtime($file);
			if ($now - $mod > 86400) continue;
			// New enough - handle
			preg_match(',/(\d+)\.log,', $file, $out);
			$module = $haveSysconfig ? ConfigModule::get($out[1]) : false;
			if ($module === false) {
				echo '<h4>Module ', $out[1], '</h4>';
			} else {
				echo '<h4>Module ', htmlspecialchars($module->title()), '</h4>';
			}
			$fh = @fopen($file, 'r');
			if ($fh === false) {
				echo '<pre>Error opening log file</pre>';
				continue;
			}
			fseek($fh, -5000, SEEK_END);
			$data = fread($fh, 5000);
			@fclose($fh);
			if ($data === false) {
				echo '<pre>Error reading from log file</pre>';
				continue;
			}
			if (strlen($data) < 4990) {
				$start = 0;
			} else {
				$start = strpos($data, "\n") + 1;
			}
			echo '<pre>', htmlspecialchars(substr($data, $start), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '</pre>';
		}
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

		echo '<pre>', htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '</pre>';
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

		echo '<pre>', htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '</pre>';
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
