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
					if (!file_exists($local) || md5_file($local) !== substr($file['md5'], 0, 32)) {
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

	private function checkFile(&$files, $name)
	{
		static $someId = 0;
		$remote = CONFIG_REMOTE_ML . "/${name}.md5";
		$localTarget = CONFIG_HTTP_DIR . "/default/${name}";
		$local = "${localTarget}.md5";
		$localLock = "${localTarget}.lck";

		// Maybe already in progress?
		if (file_exists($localLock)) {
			$data = explode(' ', file_get_contents($localLock));
			if (count($data) == 2) {
				$pid = (int)$data[0];
				if (posix_kill($pid, 0)) {
					$files[] = array(
						'file'     => $name,
						'id'       => 'id' . $someId++,
						'pid'      => $pid,
						'progress' => $data[1]
					);
					return true;
				} else {
					unlink($localLock);
				}
			 } else {
				unlink($localLock);
			 }
		}

		// Not in progress, normal display
		if (!file_exists($local) || filemtime($local) + 300 < time()) {
			if (file_exists($localTarget)) {
				$existingMd5 = md5_file($localTarget);
			} else {
				$existingMd5 = '<missing>';
			}
			if (file_put_contents($local, $existingMd5) === false) {
				@unlink($local);
				Message::addWarning('error-write', $local);
			}
		} else {
			$existingMd5 = file_get_contents($local);
		}
		$existingMd5 = strtolower(preg_replace('/[^0-9a-f]/is', '', $existingMd5));
		$remoteMd5 = Util::download($remote, 3, $code);
		$remoteMd5 = strtolower(preg_replace('/[^0-9a-f]/is', '', $remoteMd5));
		if ($code != 200) {
			Message::addError('remote-timeout', $remote, $code);
			return false;
		}
		if ($existingMd5 === $remoteMd5) {
			// Up to date
			$files[] = array(
				'file'     => $name,
				'id'       => 'id' . $someId++,
			);
			return true;
		}
		// New version on server
		$files[] = array(
			'file'        => $name,
			'id'          => 'id' . $someId++,
			'update'      => true
		);
		return true;
	}
}
