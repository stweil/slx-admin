<?php

class ConfigModule
{
	
	public static function insertAdConfig($title, $server, $searchbase, $binddn, $bindpw, $home)
	{
		// TODO: Lock table, race condition if about 500 admins insert a config at the same time
		Database::exec("INSERT INTO configtgz_module (title, moduletype, filepath, contents) "
			. " VALUES (:title, 'AD_AUTH', '', '')", array('title' => $title));
		$id = Database::lastInsertId();
		if (!is_numeric($id)) Util::traceError('Inserting new AD config to DB did not yield a numeric insert id');
		// Entry created, now try to get a free port for the proxy
		$res = Database::simpleQuery("SELECT moduleid, contents FROM configtgz_module");
		$ports = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['moduleid'] == $id) {
				// ...
			} else {
				$data = json_decode($row['contents'], true);
				if (isset($data['proxyport'])) $ports[] = $data['proxyport'];
			}
		}
		$port = 3300;
		while (in_array($port, $ports)) {
			$port++;
		}
		// Port determined, carry on...
		$ownEntry = array(
			'server' => $server,
			'searchbase' => $searchbase,
			'binddn' => $binddn,
			'bindpw' => $bindpw,
			'home' => $home,
			'proxyport' => $port
		);
		$data = json_encode($ownEntry);
		if ($data === false) Util::traceError('Serializing the AD data failed.');
		$moduleTgz = CONFIG_TGZ_LIST_DIR . '/modules/AD_AUTH_id_' . $id . '.' . mt_rand() . '.tgz';
		Database::exec("UPDATE configtgz_module SET filepath = :filename, contents = :contents WHERE moduleid = :id LIMIT 1", array(
			'id' => $id,
			'filename' => $moduleTgz,
			'contents' => $data
		));
		// Add archive file name to array before returning it
		$ownEntry['moduleid'] = $id;
		$ownEntry['filename'] = $moduleTgz;
		return $ownEntry;
	}
	
	public static function insertBrandingModule($title, $archive)
	{
		Database::exec("INSERT INTO configtgz_module (title, moduletype, filepath, contents) "
			. " VALUES (:title, 'BRANDING', '', '')", array('title' => $title));
		$id = Database::lastInsertId();
		if (!is_numeric($id)) Util::traceError('Inserting new Branding Module into DB did not yield a numeric insert id');
		// Move tgz
		$moduleTgz = CONFIG_TGZ_LIST_DIR . '/modules/BRANDING_id_' . $id . '.' . mt_rand() . '.tgz';
		$task = Taskmanager::submit('MoveFile', array(
			'source' => $archive,
			'destination' => $moduleTgz
		));
		$task = Taskmanager::waitComplete($task, 3000);
		if (Taskmanager::isFailed($task) || $task['statusCode'] !== TASK_FINISHED) {
			Taskmanager::addErrorMessage($task);
			Database::exec("DELETE FROM configtgz_module WHERE moduleid = :moduleid LIMIT 1", array(
				'moduleid' => $id
			));
			return false;
		}
		// Update with path
		Database::exec("UPDATE configtgz_module SET filepath = :filename WHERE moduleid = :id LIMIT 1", array(
			'id' => $id,
			'filename' => $moduleTgz
		));
		return true;
	}
	
}
