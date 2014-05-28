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
				'message' => 'Fehler beim Abrufen der Liste: ' . $data
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
						$file['changed'] = true;
					}
				}
			}
			echo Render::parse('minilinux/filelist', array(
				'systems' => $data['systems'],
				'token' => Session::get('token')
			));
			return;
		case 'download':
			$id = Request::post('id');
			$name = Request::post('name');
			if (!$id || !$name || strpos("$id$name", '/') !== false) {
				echo "Invalid download request";
				return;
			}
			$found = false;
			foreach ($data['systems'] as &$system) {
				if ($system['id'] !== $id) continue;
				foreach ($system['files'] as &$file) {
					if ($file['name'] !== $name) continue;
					$found = true;
					break;
				}
			}
			if (!$found) {
				echo "Nonexistent system/file: $id / $name";
				return;
			}
			$task = Taskmanager::submit('DownloadFile', array(
				'url' => CONFIG_REMOTE_ML . '/' . $id . '/' . $name,
				'destination' => CONFIG_HTTP_DIR . '/' . $id . '/' . $name
			));
			if (!isset($task['id'])) {
				echo 'Error launching download task: ' . $task['statusCode'];
				return;
			}
			echo Render::parse('minilinux/download', array(
				'name' => $name,
				'task' => $task['id']
			));
			return;
		}
	}

}
