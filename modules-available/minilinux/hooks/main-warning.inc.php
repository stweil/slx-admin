<?php

if (!is_dir(CONFIG_HTTP_DIR . '/bwlp/default')) {
	Message::addError('minilinux.please-download-minilinux', true);
	$needSetup = true;
} else {
	$needSetup = MiniLinux::generateUpdateNotice();
}