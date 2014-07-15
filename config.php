<?php

// This might leak sensitive information. Never enable in production!
define('CONFIG_DEBUG', true);

define('CONFIG_SESSION_DIR', '/tmp/openslx');
define('CONFIG_SESSION_TIMEOUT', 86400 * 3);

//define('CONFIG_SQL_BACKEND', 'mysql');
//define('CONFIG_SQL_HOST', 'localhost');
define('CONFIG_SQL_DSN', 'mysql:dbname=openslx;host=localhost');
define('CONFIG_SQL_USER', 'openslx');
define('CONFIG_SQL_PASS', 'geheim');
define('CONFIG_SQL_FORCE_UTF8', false);
//define('CONFIG_SQL_DB', 'openslx');

define ("SITE_LANGUAGES", serialize (array ("de", "en", "pt")));

define('CONFIG_TGZ_LIST_DIR', '/opt/openslx/configs');

define('CONFIG_REMOTE_ML',  'http://mltk.boot.openslx.org/update/new');

define('CONFIG_TFTP_DIR', '/srv/openslx/tftp');
define('CONFIG_HTTP_DIR', '/srv/openslx/www/boot');

define('CONFIG_IPXE_DIR', '/opt/openslx/ipxe');

define('CONFIG_VMSTORE_DIR', '/srv/openslx/nfs');
