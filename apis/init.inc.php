<?php

// via cron:
// @reboot   www-data   php /path/to/api.php init

if (!isLocalExecution())
	exit(0);

if (($report = Request::get('crashreport', false, 'string')) !== false) {
	$details = file_get_contents($report);
	EventLog::failure('Problems during bootup hook', $details);
	exit(0);
} elseif (($report = Request::get('logreport', false, 'string')) !== false) {
	$details = file_get_contents($report);
	EventLog::info('Messages during bootup hook', $details);
	exit(0);
}

Event::systemBooted();
