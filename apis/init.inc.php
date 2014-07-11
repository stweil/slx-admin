<?php

if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1')
	exit(0);

Trigger::ldadp();
Trigger::mount();
Trigger::autoUpdateServerIp();
Trigger::ipxe();
