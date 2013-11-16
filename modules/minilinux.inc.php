<?php

User::load();

if (!User::hasPermission('superadmin')) {
	Message::addError('no-permission');
	Util::redirect('?do=main');
}

function render_module()
{
	$files = array();
	checkFile($files, 'kernel');
	checkFile($files, 'initramfs-stage31');
	checkFile($files, 'stage32.sqfs');
	checkFile($files, 'vmware.sqfs');
	Render::addTemplate('page-minilinux', array('files' => $files, 'token' => Session::get('token')));
}

function checkFile(&$files, $name)
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
	$remoteMd5 = strtolower(preg_replace('/[^0-9a-f]/is', '', $existingMd5));
	if ($code != 200) {
		Message::addError('remote-timeout', $remote);
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

