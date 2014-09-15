<?php

header('Content-Type: application/xml; charset=utf-8');

// Fetch news from DB
$row = Database::queryFirst('SELECT title, content, dateline FROM news ORDER BY dateline DESC LIMIT 1');
if ($row !== false ) {

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo "<news>" . "\n";
	echo "\t" . '<headline>' . "\n";
	echo "\t\t" . $row['title'] . "\n";
	echo "\t" . '</headline>' . "\n";
	echo "\t" . "<content>" . "\n";
	echo "\t\t" . $row['content'] . "\n";
	echo "\t" . '</content>' . "\n";
	echo "\t" . "<date>" . "\n";
	echo "\t\t" . $row['dateline'] . "\n";
	echo "\t" . "</date>" . "\n";
	echo "</news>";

} else {
	// no news in DB, output a 'null' news xml
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo "<news>null</news>";
}
