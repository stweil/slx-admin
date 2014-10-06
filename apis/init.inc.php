<?php

if (!isLocalExecution())
	exit(0);

Trigger::ldadp();
Trigger::mount();
Trigger::autoUpdateServerIp();
Trigger::ipxe();
