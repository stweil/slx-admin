<?php

// via cron:
// @reboot   www-data   php /path/to/api.php init

if (!isLocalExecution())
	exit(0);

Event::systemBooted();
