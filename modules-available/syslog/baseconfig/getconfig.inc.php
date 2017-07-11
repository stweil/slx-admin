<?php

// Remote log URL
ConfigHolder::add("SLX_REMOTE_LOG", 'http://' . $_SERVER['SERVER_ADDR'] . $_SERVER['SCRIPT_NAME'] . '?do=clientlog');
