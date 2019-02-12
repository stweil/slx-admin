<?php

if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', Property::getServerIp())) {
	Message::addError('serversetup.no-ip-addr-set', true);
	$needSetup = true;
}
