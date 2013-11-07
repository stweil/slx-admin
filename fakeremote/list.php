<?php

/*
echo '[';

$first = true;
foreach (glob('./*.tgz') as $file) {
	if (!$first) echo ', ';
	$first = false;
	echo ' { "file" : "' . basename($file) . '", "description" : "<Beschreibung>" }';
}
echo ' ]';
*/

$files = array();
foreach (glob('./*.tgz') as $file) {
	$files[] = array(
		'file' => basename($file),
		'description' => 'Eine sinnvolle Beschreibung'
	);
}

echo json_encode($files);

