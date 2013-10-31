<?php

$res = Database::simpleQuery('SELECT setting.setting, setting.defaultvalue, setting.permissions, setting.description, tbl.value
	FROM setting
	LEFT JOIN setting_global AS tbl USING (setting)
	ORDER BY setting ASC'); // TODO: Add setting groups and sort order
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	echo $row['setting'] . "='" . str_replace("'", "'\"'\"'", $row['value']) . "'\n";
}
