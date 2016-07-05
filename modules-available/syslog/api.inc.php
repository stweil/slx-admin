<?php

if (empty($_POST['type'])) die('Missing options.');
$type = mb_strtolower($_POST['type']);

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') $ip = substr($ip, 7);

// TODO: Handle UUID in appropriate modules (optional)
$uuid = Request::post('uuid', '', 'string');
if (strlen($uuid) !== 36) {
	// Probably invalid UUID. What to do? Set empty or ignore?
}

/*
 * Normal logging
 */

if (!isset($_POST['description'])) die('Missing options..');

$description = $_POST['description'];
$longdesc = '';
if (isset($_POST['longdesc'])) $longdesc = $_POST['longdesc'];

if ($type{0} !== '.' && $type{0} !== '~') {

	// Spam from IP
	$row = Database::queryFirst('SELECT Count(*) AS cnt FROM clientlog WHERE clientip = :client AND dateline + 3600 > UNIX_TIMESTAMP()', array(':client' => $ip));
	if ($row !== false && $row['cnt'] > 150) exit(0);

	Database::exec('INSERT INTO clientlog (dateline, logtypeid, clientip, description, extra) VALUES (UNIX_TIMESTAMP(), :type, :client, :description, :longdesc)', array(
		'type'        => $type,
		'client'      => $ip,
		'description' => $description,
		'longdesc'    => $longdesc,
	));

}

echo "OK.\n";