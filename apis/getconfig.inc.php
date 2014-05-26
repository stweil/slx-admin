<?php

require_once 'inc/property.inc.php';

// Dump config from DB
$res = Database::simpleQuery('SELECT setting.setting, setting.defaultvalue, setting.permissions, setting.description, tbl.value
	FROM setting
	LEFT JOIN setting_global AS tbl USING (setting)
	ORDER BY setting ASC'); // TODO: Add setting groups and sort order
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	if (is_null($row['value'])) $row['value'] = $row['defaultvalue'];
	echo $row['setting'] . "='" . str_replace("'", "'\"'\"'", $row['value']) . "'\n";
}
// Additional "intelligent" config
echo "SLX_REMOTE_LOG='http://${_SERVER['SERVER_ADDR']}/slxadmin/api.php?do=clientlog'\n";

$vmstore = Property::getVmStoreConfig();

if (is_array($vmstore)) {
	switch ($vmstore['storetype']) {
	case 'internal';
		echo "SLX_VM_NFS='{$_SERVER['SERVER_ADDR']}:/srv/openslx/nfs'\n";
		break;
	case 'nfs';
		echo "SLX_VM_NFS='{$vmstore['nfsaddr']}'\n";
		break;
	case 'cifs';
		echo "SLX_VM_NFS='{$vmstore['cifsaddr']}'\n";
		break;
	}
}
