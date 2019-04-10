<?php

// Check for user data export
if (($user = Request::post('export-user', false, 'string')) !== false) {
	User::load();
	User::assertPermission('export-user-data', null, '?do=syslog');
	if (!Util::verifyToken()) {
		die('Invalid Token');
	}
	$puser = preg_quote($user);
	$exp = "$puser logged|^\[$puser\]";
	Header('Content-Type: text/plain; charset=utf-8');
	Header('Content-Disposition: attachment; filename=bwlehrpool-export-' .Util::sanitizeFilename($user) . '-' . date('Y-m-d') . '.txt');
	$srcs = [];
	$srcs[] = ['res' => Database::simpleQuery("SELECT dateline, logtypeid AS typeid, clientip, description FROM clientlog
		WHERE description REGEXP :exp
		ORDER BY dateline ASC", ['exp' => $exp])];
	if (Module::get('statistics') !== false) {
		$srcs[] = ['res' => Database::simpleQuery("SELECT dateline, typeid, clientip, data AS description FROM statistic
			WHERE username = :user
			ORDER BY dateline ASC", ['user' => $user])];
	}
	echo "# Begin log\n";
	for (;;) {
		unset($best);
		foreach ($srcs as &$src) {
			if (!isset($src['row'])) {
				$src['row'] = $src['res']->fetch(PDO::FETCH_ASSOC);
			}
			if ($src['row'] !== false && (!isset($best) || $src['row']['dateline'] < $best['dateline'])) {
				$best =& $src['row'];
			}
		}
		if (!isset($best))
			break;
		echo date('Y-m-d H:i:s', $best['dateline']), "\t", $best['typeid'], "\t", $best['clientip'], "\t", $best['description'], "\n";
		$best = null; // so we repopulate on next iteration
	}
	die("# End log\n");
}

if (empty($_POST['type'])) die('Missing options.');
$type = mb_strtolower($_POST['type']);

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') $ip = substr($ip, 7);

// TODO: Handle UUID in appropriate modules (optional)
$uuid = Request::post('uuid', '', 'string');
if (strlen($uuid) !== 36) {
	// Probably invalid UUID. What to do? Set NULL for now so the insert will succeed
	$uuid = null;
	error_log("Client log event $type without UUID");
}

/*
 * Normal logging
 */

if (!isset($_POST['description'])) die('Missing options..');

$description = $_POST['description'];
$longdesc = '';
if (isset($_POST['longdesc'])) $longdesc = $_POST['longdesc'];
$longdesc = Request::post('longdesc', '', 'string');

if ($type{0} !== '.' && $type{0} !== '~') {

	// Spam from IP
	$row = Database::queryFirst('SELECT Count(*) AS cnt FROM clientlog WHERE clientip = :client AND dateline + 1800 > UNIX_TIMESTAMP()', array(':client' => $ip));
	if ($row !== false && $row['cnt'] > 250) {
		exit(0);
	}

	$ret = Database::exec('INSERT INTO clientlog (dateline, logtypeid, clientip, machineuuid, description, extra) VALUES (UNIX_TIMESTAMP(), :type, :client, :uuid, :description, :longdesc)', array(
		'type'        => $type,
		'client'      => $ip,
		'description' => $description,
		'longdesc'    => $longdesc,
		'uuid'        => $uuid,
	), true);
	if ($ret === false) {
		error_log("Constraint failed for client log from $uuid for $type : $description");
		die("NOPE.\n");
	}

}

echo "OK.\n";
