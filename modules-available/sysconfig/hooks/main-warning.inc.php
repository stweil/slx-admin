<?php

if (!file_exists(CONFIG_HTTP_DIR . '/default/config.tgz')) {
	Message::addError('sysconfig.no-noconfig-active', true);
	$needSetup = true;
}