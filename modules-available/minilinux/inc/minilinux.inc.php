<?php

class MiniLinux
{

	const PROPERTY_KEY_FETCHTIME = 'ml-list-fetch';

	public static function updateList()
	{
		$stamp = time();
		$last = Property::get(self::PROPERTY_KEY_FETCHTIME);
		error_log('Last: ' . $last);
		if ($last !== false && $last + 10 > $stamp)
			return 0; // In progress...
		Property::set(self::PROPERTY_KEY_FETCHTIME, $stamp, 1);
		Database::exec('LOCK TABLES callback WRITE,
			minilinux_source WRITE, minilinux_branch WRITE, minilinux_version WRITE');
		Database::exec('UPDATE minilinux_source SET taskid = UUID()');
		$cutoff = time() - 3600;
		Database::exec("UPDATE minilinux_version
    		INNER JOIN minilinux_branch USING (branchid)
    		INNER JOIN minilinux_source USING (sourceid)
			SET orphan = orphan + 1 WHERE minilinux_source.lastupdate < $cutoff");
		$list = Database::queryAll('SELECT sourceid, url, taskid FROM minilinux_source');
		foreach ($list as $source) {
			Taskmanager::submit('DownloadText', array(
				'id' => $source['taskid'],
				'url' => $source['url'],
			), true);
			TaskmanagerCallback::addCallback($source['taskid'], 'mlDownload', $source['sourceid']);
		}
		Database::exec('UNLOCK TABLES');
		return count($list);
	}

	public static function listDownloadCallback($task, $sourceid)
	{
		$taskId = $task['id'];
		$data = json_decode($task['data']['content'], true);
		if (!is_array($data)) {
			EventLog::warning('Cannot download Linux version meta data for ' . $sourceid);
			error_log(print_r($task, true));
			$lastupdate = 'lastupdate';
		} else {
			if (isset($data['systems']) && is_array($data['systems'])) {
				self::addBranches($sourceid, $data['systems']);
			}
			$lastupdate = 'UNIX_TIMESTAMP()';
		}
		Database::exec("UPDATE minilinux_source SET lastupdate = $lastupdate, taskid = NULL
			WHERE sourceid = :sourceid AND taskid = :taskid",
			['sourceid' => $sourceid, 'taskid' => $taskId]);
	}

	private static function addBranches($sourceid, $systems)
	{
		foreach ($systems as $system) {
			if (!self::isValidIdPart($system['id']))
				continue;
			$branchid = $sourceid . '/' . $system['id'];
			$title = empty($system['title']) ? $branchid : $system['title'];
			$description = empty($system['description']) ? '' : $system['description'];
			Database::exec('INSERT INTO minilinux_branch (branchid, sourceid, title, description)
					VALUES (:branchid, :sourceid, :title, :description)
					ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)', [
				'branchid' => $branchid,
				'sourceid' => $sourceid,
				'title' => $title,
				'description' => $description,
			]);
			if (isset($system['versions']) && is_array($system['versions'])) {
				self::addVersions($branchid, $system['versions']);
			}
		}
	}

	private static function addVersions($branchid, $versions)
	{
		foreach ($versions as $version) {
			self::addVersion($branchid, $version);
		}
	}

	private static function addVersion($branchid, $version)
	{
		if (!self::isValidIdPart($version['version'])) {
			error_log("Ignoring version {$version['version']} from $branchid: Invalid characters in ID");
			return;
		}
		if (empty($version['files']) && empty($version['cmdline'])) {
			error_log("Ignoring version {$version['version']} from $branchid: Neither file list nor command line");
			return;
		}
		$versionid = $branchid . '/' . $version['version'];
		$title = empty($version['title']) ? '' : $version['title'];
		$dateline = empty($version['releasedate']) ? time() : (int)$version['releasedate'];
		unset($version['version'], $version['title'], $version['releasedate']);
		// Sanitize files array
		if (!isset($version['files']) || !is_array($version['files'])) {
			unset($version['files']);
		} else {
			foreach (array_keys($version['files']) as $key) {
				$file =& $version['files'][$key];
				if (empty($file['name'])) {
					error_log("Ignoring version {$version['version']} from $branchid: Entry in file list has missing file name");
					return;
				}
				if ($file['name'] === 'menu.txt' || $file['name'] === 'menu-debug.txt') {
					unset($version['files'][$key]);
					continue;
				}
				if (empty($file['gpg'])) {
					error_log("Ignoring version {$version['version']} from $branchid: {$file['name']} has no GPG signature");
					return;
				}
				if (preg_match(',/\.\.|\.\./|/|\x00,', $file['name']) > 0) { // Invalid chars
					error_log("Ignoring version {$version['version']} from $branchid: {$file['name']} contains invalid characters");
					return;
				}
				if (isset($file['md5'])) {
					$file['md5'] = strtolower($file['md5']);
				}
			}
			unset($file);
			$version['files'] = array_values($version['files']);
		}
		$data = json_encode($version);
		Database::exec('INSERT INTO minilinux_version (versionid, branchid, title, dateline, data, orphan)
					VALUES (:versionid, :branchid, :title, :dateline, :data, 0)
					ON DUPLICATE KEY UPDATE title = VALUES(title), data = VALUES(data), orphan = 0', [
			'versionid' => $versionid,
			'branchid' => $branchid,
			'title' => $title,
			'dateline' => $dateline,
			'data' => $data,
		]);
	}

	private static function isValidIdPart($str)
	{
		return preg_match('/^[a-z0-9_\-]+$/', $str) > 0;
	}

	public static function validateDownloadTask($versionid, $taskid)
	{
		if ($taskid === null)
			return false;
		$task = Taskmanager::status($taskid);
		if (Taskmanager::isTask($task) && !Taskmanager::isFailed($task)
				&& (is_dir(CONFIG_HTTP_DIR . '/' . $versionid) || !Taskmanager::isFinished($task)))
			return $task['id'];
		Database::exec('UPDATE minilinux_version SET taskid = NULL
				WHERE versionid = :versionid AND taskid = :taskid',
			['versionid' => $versionid, 'taskid' => $taskid]);
		return false;
	}

	/**
	 * Download the files for the given version id
	 * @param $versionid
	 * @return bool
	 */
	public static function downloadVersion($versionid)
	{
		$ver = Database::queryFirst('SELECT s.url, s.pubkey, v.versionid, v.taskid, v.data FROM minilinux_version v
			INNER JOIN minilinux_branch b USING (branchid)
			INNER JOIN minilinux_source s USING (sourceid)
			WHERE versionid = :versionid',
			['versionid' => $versionid]);
		if ($ver === false)
			return false;
		$taskid = self::validateDownloadTask($versionid, $ver['taskid']);
		if ($taskid !== false)
			return $taskid;
		$data = json_decode($ver['data'], true);
		if (!is_array($data)) {
			EventLog::warning("Cannot download Linux '$versionid': Corrupted meta data.", $ver['data']);
			return false;
		}
		if (empty($data['files']))
			return false;
		$list = [];
		$legacyDir = preg_replace(',^[^/]*/,', '', $versionid);
		foreach ($data['files'] as $file) {
			if (empty($file['name']))
				continue;
			$list[] = [
				'id' => self::fileToId($versionid, $file['name']),
				'url' => empty($file['url'])
					? ($ver['url'] . '/' . $legacyDir . '/' . $file['name'])
					: ($ver['url'] . '/' . $file['url']),
				'fileName' => $file['name'],
				'gpg' => $file['gpg'],
			];
		}
		error_log(print_r($list, true));
		$uuid = Util::randomUuid();
		Database::exec('LOCK TABLES minilinux_version WRITE');
		$aff = Database::exec('UPDATE minilinux_version SET taskid = :taskid WHERE versionid = :versionid AND taskid IS NULL',
			['taskid' => $uuid, 'versionid' => $versionid]);
		if ($aff > 0) {
			$task = Taskmanager::submit('DownloadFiles', [
				'id' => $uuid,
				'baseDir' => CONFIG_HTTP_DIR . '/' . $versionid,
				'gpgPubKey' => $ver['pubkey'],
				'files' => $list,
			]);
			if (Taskmanager::isFailed($task)) {
				error_log(print_r($task, true));
				$task = false;
			} else {
				$task = $task['id'];
			}
		} else {
			$task = false;
		}
		Database::exec('UNLOCK TABLES');
		if ($aff === 0)
			return self::downloadVersion($versionid);
		return $task;
	}

	public static function fileToId($versionid, $fileName)
	{
		return 'x' . substr(md5($fileName . $versionid), 0, 8);
	}

}