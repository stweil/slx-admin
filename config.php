<?php

// This might leak sensitive information. Never enable in production!
define('CONFIG_DEBUG', true);

define('CONFIG_SESSION_DIR', '/tmp/openslx');
define('CONFIG_SESSION_TIMEOUT', 86400);

//define('CONFIG_SQL_BACKEND', 'mysql');
//define('CONFIG_SQL_HOST', 'localhost');
define('CONFIG_SQL_DSN', 'mysql:dbname=openslx;host=localhost');
define('CONFIG_SQL_USER', 'openslx');
define('CONFIG_SQL_PASS', 'geheim');
//define('CONFIG_SQL_DB', 'openslx');

define('CONFIG_TGZ_LIST_DIR', '/tmp/configs');
define('CONFIG_HTTP_DIR', '/tmp/active-config');

define('CONFIG_REMOTE_TGZ', 'http://127.0.0.1/fakeremote');

