<?php

class Page_MiniLinux extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		if (!(User::hasPermission("show") || User::hasPermission("update"))) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}
	}

	protected function doRender()
	{
		Render::addTemplate('page-minilinux', array(
			'listurl' => '?do=MiniLinux&async=true&action=list'
		));
		Render::addFooter('<script> $(window).load(function (e) {
			loadSystemList(0);
			}); // </script>');
	}
	
	protected function doAjax()
	{
		$data = Property::getVersionCheckInformation();
		if (!is_array($data) || !isset($data['systems'])) {
			echo Render::parse('messagebox', array(
				'type' => 'danger',
				'message' => 'Failed to retrieve the list: ' . print_r($data, true)
			), 'main');
			return;
		}
		$action = Request::any('action');
		$selectedVersion = (int)Request::any('version', 0);
		switch ($action) {
		case 'list':
			$count = 0;
			foreach ($data['systems'] as &$system) {
				// Get latest version, build simple array of all version numbers
				$versionNumbers = array();
				$selected = false;
				foreach ($system['versions'] as $version) {
					if (!is_numeric($version['version']) || $version['version'] < 1)
						continue;
					if ($selectedVersion === 0 && ($selected === false || $selected['version'] < $version['version']))
						$selected = $version;
					elseif ($version['version'] == $selectedVersion)
						$selected = $version;
					$versionNumbers[(int)$version['version']] = array(
						'version' => $version['version']
					);
				}
				if ($selected === false) continue; // No versions for this system!?
				ksort($versionNumbers);
				// Mark latest version as selected
				$versionNumbers[(int)$selected['version']]['selected'] = true;
				// Add status information to system and its files
				foreach ($selected['files'] as &$file) {
					$file['uid'] = 'dlid' . $count++;
					$local = CONFIG_HTTP_DIR . '/' . $system['id'] . '/' . $file['name'];
					if (!file_exists($local) || filesize($local) !== $file['size'] || filemtime($local) < $file['mtime']) {
						$file['fileChanged'] = true;
						$system['systemChanged'] = true;
					}
					$taskId = Property::getDownloadTask($file['md5']);
					if ($taskId !== false) {
						$task = Taskmanager::status($taskId);
						if (isset($task['data']['progress'])) {
							$file['download'] = Render::parse('download', array(
								'task' => $taskId,
								'name' => $file['name']
							));
						}
					}
				}
				unset($system['versions']);
				$system['files'] = $selected['files'];
				$system['version'] = $selected['version'];
			}
			$data['versions'] = array_values($versionNumbers);
			$data['allowedToUpdate'] = User::hasPermission("update");
			echo Render::parse('filelist', $data);
			return;
		case 'download':
			if (User::hasPermission("update")) {
				$id = Request::post('id');
				$name = Request::post('name');
				if (!$id || !$name || strpos("$id$name", '/') !== false) {
					echo "Invalid download request";
					return;
				}
				$file = false;
				$gpg = 'missing';
				foreach ($data['systems'] as &$system) {
					if ($system['id'] !== $id) continue;
					foreach ($system['versions'] as &$version) {
						if ($version['version'] != $selectedVersion) continue;
						foreach ($version['files'] as &$f) {
							if ($f['name'] !== $name) continue;
							$file = $f;
							if (!empty($f['gpg'])) $gpg = $f['gpg'];
							break;
						}
					}
				}
				if ($file === false) {
					echo "Nonexistent system/file: $id / $name";
					return;
				}
				$task = Taskmanager::submit('DownloadFile', array(
					'url' => CONFIG_REMOTE_ML . '/' . $id . '/' . $selectedVersion . '/' . $name,
					'destination' => CONFIG_HTTP_DIR . '/' . $id . '/' . $name,
					'gpg' => $gpg
				));
				if (!isset($task['id'])) {
					echo 'Error launching download task: ' . $task['statusCode'];
					return;
				}
				Property::setDownloadTask($file['md5'], $task['id']);
				echo Render::parse('download', array(
					'name' => $name,
					'task' => $task['id']
				));
				return;
			}
		}
	}

}
