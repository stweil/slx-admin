<?php

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

