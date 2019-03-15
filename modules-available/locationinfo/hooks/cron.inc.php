<?php

$cutoff = time() - 86400 * 30;
Database::exec('DELETE FROM locationinfo_backendlog WHERE dateline < :cutoff',
	compact('cutoff'));