<?php

class Page_MiniLinux extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}
	}

	protected function doRender()
	{
		Render::addTemplate('page-minilinux', array(
			'listurl' => '?do=MiniLinux&async=true&action=list'
		));
	}
	
	protected function doAjax()
	{
		$data = Property::getVersionCheckInformation();
		if (!is_array($data) || !isset($data['systems'])) {
			echo Render::parse('messagebox-error', array(
				'message' => 'Failed to retrieve the list: ' . print_r($data, true)
			));
			return;
		}
		$action = Request::any('action');
		switch ($action) {
		case 'list':
			$count = 0;
			foreach ($data['systems'] as &$system) {
				foreach ($system['files'] as &$file) {
					$file['uid'] = 'dlid' . $count++;
					$local = CONFIG_HTTP_DIR . '/' . $system['id'] . '/' . $file['name'];
					if (!file_exists($local) || filesize($local) !== $file['size'] || md5_file($local) !== substr($file['md5'], 0, 32)) {
						$file['fileChanged'] = true;
						$system['systemChanged'] = true;
					}
					$taskId = Property::getDownloadTask($file['md5']);
					if ($taskId !== false) {
						$task = Taskmanager::status($taskId);
						if (isset($task['data']['progress'])) {
							$file['download'] = Render::parse('minilinux/download', array(
								'task' => $taskId,
								'name' => $file['name']
							));
						}
					}
				}
			}
			echo Render::parse('minilinux/filelist', array(
				'systems' => $data['systems']
			));
			return;
		case 'download':
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
				foreach ($system['files'] as &$f) {
					if ($f['name'] !== $name) continue;
					$file = $f;
					if (!empty($f['gpg'])) $gpg = $f['gpg'];
					break;
				}
			}
			if ($file === false) {
				echo "Nonexistent system/file: $id / $name";
				return;
			}
			$task = Taskmanager::submit('DownloadFile', array(
				'url' => CONFIG_REMOTE_ML . '/' . $id . '/' . $name,
				'destination' => CONFIG_HTTP_DIR . '/' . $id . '/' . $name,
				'gpg' => $gpg
			));
			if (!isset($task['id'])) {
				echo 'Error launching download task: ' . $task['statusCode'];
				return;
			}
			Property::setDownloadTask($file['md5'], $task['id']);
			echo Render::parse('minilinux/download', array(
				'name' => $name,
				'task' => $task['id']
			));
			return;
		}
	}

}
