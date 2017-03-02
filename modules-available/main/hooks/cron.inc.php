<?php

switch (mt_rand(1, 10)) {
case 2:
	Database::exec("DELETE FROM property_list WHERE dateline <> 0 AND dateline < UNIX_TIMESTAMP()");
	break;
case 3:
	Database::exec("DELETE FROM property WHERE dateline <> 0 AND dateline < UNIX_TIMESTAMP()");
	break;
case 4:
	Database::exec("DELETE FROM callback WHERE (UNIX_TIMESTAMP() - dateline) > 86400");
	break;
}

Trigger::checkCallbacks();
