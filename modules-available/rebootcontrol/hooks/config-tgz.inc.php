<?php

$pubkey = SSHKey::getPublicKey();
$tmpfile = '/tmp/bwlp-' . md5($pubkey) . '-2.tar';
if (!is_file($tmpfile) || !is_readable($tmpfile) || filemtime($tmpfile) + 86400 < time()) {
	if (file_exists($tmpfile)) {
		unlink($tmpfile);
	}
	try {
		$a = new PharData($tmpfile);
		$a["/etc/ssh/mgmt/authorized_keys"] = $pubkey;
		$a["/etc/ssh/mgmt/authorized_keys"]->chmod(0600);
		$file = $tmpfile;
	} catch (Exception $e) {
		EventLog::failure('Could not include ssh key for reboot-control in config.tgz', (string)$e);
	}
} elseif (is_file($tmpfile) && is_readable($tmpfile)) {
	$file = $tmpfile;
}
