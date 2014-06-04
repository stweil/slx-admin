<?php

function escape($string)
{
	return str_replace("'", "\\'", $string);
}

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

// Remote log URL
echo "SLX_REMOTE_LOG='http://" . escape($_SERVER['SERVER_ADDR']) . "/slxadmin/api.php?do=clientlog'\n";

// VMStore path and type
$vmstore = Property::getVmStoreConfig();
if (is_array($vmstore)) {
	switch ($vmstore['storetype']) {
	case 'internal';
		echo "SLX_VM_NFS='" . escape($_SERVER['SERVER_ADDR']) . ":/srv/openslx/nfs'\n";
		break;
	case 'nfs';
		echo "SLX_VM_NFS='" . escape($vmstore['nfsaddr']) . "'\n";
		break;
	case 'cifs';
		echo "SLX_VM_NFS='" . escape($vmstore['cifsaddr']) . "'\n";
		echo "SLX_VM_NFS_USER='" . escape($vmstore['cifsuserro']) . "'\n";
		echo "SLX_VM_NFS_PASSWD='" . escape($vmstore['cifspasswdro']) . "'\n";
		break;
	}
}
