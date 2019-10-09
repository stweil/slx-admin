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

		if (Request::isPost()) {
			$show = Request::post('show', false, 'string');
			if ($show === 'delete') {
				$this->deleteVersion();
			} elseif ($show === 'updatesources') {
				$this->updateSources();
			}
			Util::redirect('?do=minilinux');
		}

		User::assertPermission('view');
	}

	protected function doRender()
	{
		Render::addTemplate('page-minilinux');
		// List branches and versions
		$branches = Database::queryAll('SELECT sourceid, branchid, title FROM minilinux_branch ORDER BY title ASC');
		$versions = $this->queryAllVersionsByBranch();
		foreach ($branches as &$branch) {
			if (isset($versions[$branch['branchid']])) {
				$branch['versionlist'] = $this->renderVersionList($versions[$branch['branchid']]);
			}
		}
		Render::addTemplate('branches', ['branches' => $branches]);
		// List sources
		$res = Database::simpleQuery('SELECT sourceid, title, url, lastupdate, pubkey FROM minilinux_source ORDER BY title, sourceid');
		$data = ['list' => [], 'show_refresh' => true];
		$tooOld = strtotime('-7 days');
		$showRefresh = strtotime('-10 minutes');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['lastupdate_s'] = Util::prettyTime($row['lastupdate']);
			if ($row['lastupdate'] != 0 && $row['lastupdate'] < $tooOld) {
				$row['update_class'] = 'text-danger';
			}
			if ($row['lastupdate'] > $showRefresh) {
				$data['show_refresh'] = false;
			}
			$data['list'][] = $row;
		}
		Render::addTemplate('sources', $data);
	}
	
	protected function doAjax()
	{
		User::load();
		$show = Request::post('show', false, 'string');
		if ($show === 'version') {
			$this->ajaxVersion();
		} elseif ($show === 'download') {
			$this->ajaxDownload();
		}
	}

	private function queryAllVersionsByBranch()
	{
		$list = [];
		$res = Database::simpleQuery('SELECT branchid, versionid, title, dateline, orphan, taskid
			FROM minilinux_version ORDER BY branchid, dateline, versionid');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$list[$row['branchid']][] = $row;
		}
		return $list;
	}

	private function renderVersionList($versions)
	{
		foreach ($versions as &$version) {
			$version['dateline_s'] = Util::prettyTime($version['dateline']);
			$version['orphan'] = ($version['orphan'] > 5);
			$version['installed'] = is_dir(CONFIG_HTTP_DIR . '/' . $version['versionid']);
			$version['downloading'] = $version['taskid'] && Taskmanager::isRunning(Taskmanager::status($version['taskid']));
		}
		return Render::parse('versionlist', ['versions' => $versions]);
	}

	private function ajaxVersion()
	{
		User::assertPermission('view');
		$verify = Request::post('verify', false, 'bool');
		$versionid = Request::post('version', false, 'string');
		if ($versionid === false) {
			die('What!');
		}
		$ver = Database::queryFirst('SELECT versionid, taskid, data FROM minilinux_version WHERE versionid = :versionid',
			['versionid' => $versionid]);
		if ($ver === false) {
			die('No such version');
		}
		$versionid = $ver['versionid']; // Just to be sure -- should be safe for building a path either way
		$data = json_decode($ver['data'], true);
		if (!is_array($data)) {
			die('Corrupted data');
		}
		$data['versionid'] = $versionid;
		$data['dltask'] = MiniLinux::validateDownloadTask($versionid, $ver['taskid']);
		$data['verify_button'] = !$verify && $data['dltask'] === false;
		if (is_array($data['files'])) {
			$sort = [];
			foreach ($data['files'] as &$file) {
				if (empty($file['name'])) {
					$sort[] = 'zzz' . implode(',', $file);
					continue;
				}
				$sort[] = $file['name'];
				$s = $this->getFileState($versionid, $file, $verify);
				if ($s !== self::FILE_OK) {
					$data['verify_button'] = false;
					$data['download_button'] = !$data['dltask'];
				}
				if ($s !== self::FILE_MISSING) {
					$data['delete_button'] = true;
				}
				$file['state'] = $this->fileStateToString($s);
				if (isset($file['size'])) {
					$file['size_s'] = Util::readableFileSize($file['size']);
				}
				if (isset($file['mtime'])) {
					$file['mtime_s'] = Util::prettyTime($file['mtime']);
				}
				if ($data['dltask']) {
					$file['fileid'] = MiniLinux::fileToId($versionid, $file['name']);
				}
			}
			unset($file);
			array_multisort($sort, SORT_ASC, $data['files']);
		}
		echo Render::parse('filelist', $data);
	}

	const FILE_OK = 0;
	const FILE_MISSING = 1;
	const FILE_SIZE_MISMATCH = 2;
	const FILE_CHECKSUM_BAD = 3;

	private function getFileState($versionid, $file, $verify)
	{
		$path = CONFIG_HTTP_DIR . '/' . $versionid . '/' . $file['name'];
		if (!is_file($path))
			return self::FILE_MISSING;
		if (isset($file['size']) && filesize($path) != $file['size'])
			return self::FILE_SIZE_MISMATCH;
		if ($verify) {
			// TODO: Others
			if (isset($file['md5'])) {
				if (md5_file($path) !== $file['md5'])
					return self::FILE_CHECKSUM_BAD;
			}
		}
		return self::FILE_OK;
	}

	private function fileStateToString($state)
	{
		switch ($state) {
		case self::FILE_CHECKSUM_BAD:
			return Dictionary::translate('file-checksum-bad', true);
		case self::FILE_SIZE_MISMATCH:
			return Dictionary::translate('file-size-mismatch', true);
		case self::FILE_MISSING:
			return Dictionary::translate('file-missing', true);
		case self::FILE_OK:
			return Dictionary::translate('file-ok', true);
		}
		return '???';
	}

	private function ajaxDownload()
	{
		User::assertPermission('update');
		$version = Request::post('version', false, 'string');
		if ($version === false) {
			die('No version');
		}
		$task = MiniLinux::downloadVersion($version);
		if ($task === false) {
			Message::addError('no-such-version', $version);
			Message::renderList();
		} else {
			$this->ajaxVersion();
		}
	}

	private function deleteVersion()
	{
		User::assertPermission('delete');
		$versionid = Request::post('version', false, 'string');
		if ($versionid === false) {
			Message::addError('main.parameter-missing', 'versionid');
			return;
		}
		$version = Database::queryFirst('SELECT versionid FROM minilinux_version WHERE versionid = :versionid',
			['versionid' => $versionid]);
		if ($version === false) {
			Message::addError('no-such-version');
			return;
		}
		$path = CONFIG_HTTP_DIR . '/' . $version['versionid'];
		$task = Taskmanager::submit('DeleteDirectory', [
			'path' => $path,
			'recursive' => true,
		]);
		if ($task !== false) {
			$task = Taskmanager::waitComplete($task, 2500);
			if (Taskmanager::isFailed($task)) {
				Message::addError('delete-error', $versionid, $task['data']['error']);
			} else {
				Message::addSuccess('version-deleted', $versionid);
			}
		}
	}

	private function updateSources()
	{
		$ret = MiniLinux::updateList();
		if ($ret > 0) {
			sleep(2);
			Trigger::checkCallbacks();
		}
	}

}
