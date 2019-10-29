<?php

if (!is_dir(CONFIG_HTTP_DIR . '/default')) {
	Message::addError('minilinux.please-download-minilinux', true);
	$needSetup = true;
} else {
	$needSetup = MiniLinux::generateUpdateNotice();
}