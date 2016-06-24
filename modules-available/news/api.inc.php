<?php

header('Content-Type: application/xml; charset=utf-8');

$type = Request::any('type', 'news', 'string');

// Fetch news from DB
$row = Database::queryFirst('SELECT title, content, dateline FROM vmchooser_pages'
	. ' WHERE type = :type ORDER BY dateline DESC LIMIT 1', compact('type'));
if ($row !== false ) {

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo "<news>" . "\n";
	echo "\t" . '<headline>' . "\n";
	echo "\t\t" . htmlspecialchars($row['title']) . "\n";
	echo "\t" . '</headline>' . "\n";
	echo "\t" . "<info>" . "\n";
	echo "\t\t" . htmlspecialchars(nl2br($row['content'])) . "\n";
	echo "\t" . '</info>' . "\n";
	echo "\t" . "<date>" . "\n";
	echo "\t\t" . $row['dateline'] . "\n";
	echo "\t" . "</date>" . "\n";
	echo "</news>";

} else {
	// no news in DB, output a 'null' news xml
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo "<news>null</news>";
}
