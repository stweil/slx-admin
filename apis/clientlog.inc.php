<?php

if (!isset($_POST['type']) || !isset($_POST['description'])) die('Missing options');

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') $ip = substr($ip, 7);
$type = mb_strtolower($_POST['type']);
$description = $_POST['description'];
$longdesc = '';
if (isset($_POST['longdesc'])) $longdesc = $_POST['longdesc'];

// Spam from IP
$row = Database::queryFirst('SELECT Count(*) AS cnt FROM clientlog WHERE clientip = :client AND dateline + 3600 > UNIX_TIMESTAMP()', array(':client' => $ip));
if ($row !== false && $row['cnt'] > 150) exit(0);


if ($type{0} === '.' && preg_match('#^\[([^\]]+)\]\s*(.*)$#', $description, $out)) {
	// Special case '.'-type:
	Database::exec('INSERT INTO statistic (dateline, typeid, clientip, username, data) VALUES (UNIX_TIMESTAMP(), :type, :client, :username, :data)', array(
		'type'        => $type,
		'client'      => $ip,
		'username'    => $out[1],
		'data'        => $out[2],
	));
	
} else {
	
	Database::exec('INSERT INTO clientlog (dateline, logtypeid, clientip, description, extra) VALUES (UNIX_TIMESTAMP(), :type, :client, :description, :longdesc)', array(
		'type'        => $type,
		'client'      => $ip,
		'description' => $description,
		'longdesc'    => $longdesc,
	));
}

echo "OK.\n";

