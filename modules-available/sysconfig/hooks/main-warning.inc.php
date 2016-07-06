<?php

if (false === Database::queryFirst("SELECT locationid FROM configtgz_location WHERE locationid = 0")) {
	Message::addError('sysconfig.no-noconfig-active', true);
	$needSetup = true;
}
