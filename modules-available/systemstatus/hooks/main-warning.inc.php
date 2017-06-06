<?php

if (file_exists('/run/reboot-required.pkgs')) {
	$lines = file('/run/reboot-required.pkgs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$lines = array_unique($lines);
	Message::addInfo('systemstatus.update-reboot-required', true, implode(', ', $lines));
}