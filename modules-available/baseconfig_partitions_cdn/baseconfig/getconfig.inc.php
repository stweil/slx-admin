<?php

$config = '';
$res = Database::simpleQuery('SELECT partition_id, size, mount_point, options FROM setting_partition WHERE user = :user',
	array('user'=>$_GET['user']));
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	$config .= "\n" . $row['partition_id'] . "," . $row['size'];
	if(strlen($row['mount_point']) > 0)
		$config .= "," . $row['mount_point'];
	if(strlen($row['options']) > 0)
		$config .= "," . $row['options'];
}
$config .= "\n";

// vm list url. doesn't really fit anywhere, seems to be a tie between here and dozmod
$configVars["SLX_PARTITION_TABLE"] = $config;
unset($config);