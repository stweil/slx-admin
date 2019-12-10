<?php

class MiniLinux
{

	const PROPERTY_KEY_FETCHTIME = 'ml-list-fetch';

	const PROPERTY_DEFAULT_BOOT = 'ml-default';

	const PROPERTY_DEFAULT_BOOT_EFFECTIVE = 'ml-default-eff';

	const INVALID = 'invalid';

	/*
	 * Update of available versions by querying sources
	 */

	/**
	 * Query all known sources for meta data
	 * @return int number of sources query was just initialized for
	 */
	public static function updateList()
	{
		$stamp = time();
		$last = Property::get(self::PROPERTY_KEY_FETCHTIME);
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
			TaskmanagerCallback::addCallback($source['taskid'], 'mlGotList', $source['sourceid']);
		}
		Database::exec('UNLOCK TABLES');
		return count($list);
	}

	/**
	 * Called when downloading metadata from a specific update source is finished
	 * @param mixed $task task structure
	 * @param string $sourceid see minilinux_source table
	 */
	public static function listDownloadCallback($task, $sourceid)
	{
		if ($task['statusCode'] !== 'TASK_FINISHED')
			return;
		$taskId = $task['id'];
		$data = json_decode($task['data']['content'], true);
		if (!is_array($data)) {
			EventLog::warning('Cannot download Linux version meta data for ' . $sourceid);
			$lastupdate = 'lastupdate';
		} else {
			if (@is_array($data['systems'])) {
				self::addBranches($sourceid, $data['systems']);
			}
			$lastupdate = 'UNIX_TIMESTAMP()';
		}
		Database::exec("UPDATE minilinux_source SET lastupdate = $lastupdate, taskid = NULL
			WHERE sourceid = :sourceid AND taskid = :taskid",
			['sourceid' => $sourceid, 'taskid' => $taskId]);
		// Clean up -- delete orphaned versions that are not installed
		Database::exec('DELETE FROM minilinux_version WHERE orphan > 4 AND installed = 0');
		// FKC makes sure we only delete orphaned ones
		Database::exec('DELETE IGNORE FROM minilinux_branch WHERE 1', [], true);
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
			if (@is_array($system['versions'])) {
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

	/*
	 * Download of specific version
	 */

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
				$task = false;
			} else {
				$task = $task['id'];
			}
		} else {
			$task = false;
		}
		Database::exec('UNLOCK TABLES');
		if ($task !== false) {
			// Callback for db column
			TaskmanagerCallback::addCallback($task, 'mlGotLinux', $versionid);
		}
		if ($aff === 0)
			return self::downloadVersion($versionid);
		return $task;
	}

	public static function fileToId($versionid, $fileName)
	{
		return 'x' . substr(md5($fileName . $versionid), 0, 8);
	}

	/*
	 * Check status, availability of updates
	 */

	/**
	 * Geenrate messages regarding setup und update availability.
	 * @return bool true if severe problems were found, false otherwise
	 */
	public static function generateUpdateNotice()
	{
		// Messages in here are with module name, as required by the
		// main-warning hook.
		$default = Property::get(self::PROPERTY_DEFAULT_BOOT);
		if ($default === false) {
			Message::addError('minilinux.no-default-set', true);
			return true;
		}
		$installed = self::updateCurrentBootSetting();
		$effective = Property::get(self::PROPERTY_DEFAULT_BOOT_EFFECTIVE);
		$slashes = substr_count($default, '/');
		if ($slashes === 1) {
			// Brônche, always latest version
			$latest = Database::queryFirst('SELECT versionid FROM minilinux_version
				WHERE branchid = :branchid ORDER BY dateline DESC', ['branchid' => $default]);
			if ($latest === false) {
				Message::addError('minilinux.default-is-invalid', true);
				return true;
			} elseif ($latest['versionid'] !== $effective) {
				Message::addInfo('minilinux.default-update-available', true, $default, $latest['versionid']);
			}
		} elseif ($slashes === 2) {
			// Specific version selected
			if ($effective === self::INVALID) {
				Message::addError('minilinux.default-is-invalid', true);
				return true;
			}
		}
		if (!$installed) {
			Message::addError('minilinux.default-not-installed', true, $default);
			return true;
		}
		return false;
	}

	/**
	 * Update the effective current default version to boot.
	 * If the version does not exist, it is set to INVALID.
	 * Function returns whether the currently selected version is
	 * actually installed locally.
	 * @return bool true if installed locally, false otherwise
	 */
	public static function updateCurrentBootSetting()
	{
		$default = Property::get(self::PROPERTY_DEFAULT_BOOT);
		if ($default === false)
			return false;
		$slashes = substr_count($default, '/');
		if ($slashes === 2) {
			// Specific version
			$ver = Database::queryFirst('SELECT versionid, installed FROM minilinux_version
				WHERE versionid = :versionid', ['versionid' => $default]);
		} elseif ($slashes === 1) {
			// Latest from branch
			$ver = Database::queryFirst('SELECT versionid, installed FROM minilinux_version
				WHERE branchid = :branchid AND installed = 1 ORDER BY dateline DESC', ['branchid' => $default]);
		} else {
			// Unknown
			return false;
		}
		// Determine state
		if ($ver === false) { // Doesn't exist
			Property::set(self::PROPERTY_DEFAULT_BOOT_EFFECTIVE, self::INVALID);
			return false;
		}
		Property::set(self::PROPERTY_DEFAULT_BOOT_EFFECTIVE, $ver['versionid']);
		return $ver['installed'] != 0;
	}

	public static function linuxDownloadCallback($task, $versionid)
	{
		self::setInstalledState($versionid, $task['statusCode'] === 'TASK_FINISHED');
	}

	public static function setInstalledState($versionid, $installed)
	{
		settype($installed, 'int');
		error_log("Setting $versionid to $installed");
		Database::exec('UPDATE minilinux_version SET installed = :installed WHERE versionid = :versionid', [
			'versionid' => $versionid,
			'installed' => $installed,
		]);
	}

	public static function queryAllVersionsByBranch()
	{
		$list = [];
		$res = Database::simpleQuery('SELECT branchid, versionid, title, dateline, orphan, taskid, installed
			FROM minilinux_version ORDER BY branchid, dateline, versionid');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$list[$row['branchid']][$row['versionid']] = $row;
		}
		return $list;
	}

}