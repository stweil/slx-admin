<?php

class ConfigModule
{
	
	public static function insertAdConfig($title, $server, $searchbase, $binddn, $bindpw)
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
			'proxyport' => $port
		);
		$data = json_encode($ownEntry);
		if ($data === false) Util::traceError('Serializing the AD data failed.');
		$name = CONFIG_TGZ_LIST_DIR . '/modules/AD_AUTH_id_' . $id . '.' . mt_rand() . '.tgz';
		Database::exec("UPDATE configtgz_module SET filepath = :filename, contents = :contents WHERE moduleid = :id LIMIT 1", array(
			'id' => $id,
			'filename' => $name,
			'contents' => json_encode($ownEntry)
		));
		// Add archive file name to array before returning it
		$ownEntry['moduleid'] = $id;
		$ownEntry['filename'] = $name;
		return $ownEntry;
	}
	
}
