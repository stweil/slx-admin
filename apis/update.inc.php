<?php

//
// TODO: Modularize
//

$targetVersion = Database::getExpectedSchemaVersion();

function fatal($message)
{
	if (tableExists('eventlog'))
		EventLog::failure($message);
	die("$message\n");
}

// #######################

$res = Database::queryFirst("SELECT value FROM property WHERE name = 'webif-version' LIMIT 1", array(), true);

$currentVersion = (int) ($res === false ? 1 : $res['value']);

if ($currentVersion >= $targetVersion)
	die('Up to date :-)');

while ($currentVersion < $targetVersion) {

	$function = 'update_' . $currentVersion;

	if (!function_exists($function))
		fatal("Don't know how to update from version $currentVersion to $targetVersion :-(");

	if (!$function())
		fatal("Update from $currentVersion to $targetVersion failed! :-(");

	$currentVersion++;

	$ret = Database::exec("INSERT INTO property (name, value) VALUES ('webif-version', :version) ON DUPLICATE KEY UPDATE value = VALUES(value)", array('version' => $currentVersion), false);
	if ($ret === false)
		fatal('Writing version information back to DB failed. Next update will probably break.');

	if ($currentVersion < $targetVersion) {
		echo("Updated to $currentVersion...\n");
	}
}

// TEMPORARY HACK; Rebuild AD configs.. move somewhere else
$list = ConfigModule::getAll('AdAuth');
if ($list === false) {
	Message::addError('ad-config-failed');
} else {
	foreach ($list as $ad) {
		$ad->generate(false);
	}
}

Message::addSuccess('db-update-done');
if (tableExists('eventlog'))
	EventLog::info("Database updated to version $currentVersion");
Util::redirect('index.php?do=Main');
