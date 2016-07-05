<?php

if (!file_exists(CONFIG_HTTP_DIR . '/default/kernel') || !file_exists(CONFIG_HTTP_DIR . '/default/initramfs-stage31') || !file_exists(CONFIG_HTTP_DIR . '/default/stage32.sqfs')) {
	Message::addError('minilinux.please-download-minilinux', true);
	$needSetup = true;
}