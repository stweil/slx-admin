<?php

$res = Database::simpleQuery('SELECT h.hwname FROM statistic_hw h'
	. " INNER JOIN statistic_hw_prop p ON (h.hwid = p.hwid AND p.prop = :projector)"
	. " WHERE h.hwtype = :screen ORDER BY h.hwname ASC", array(
	'projector' => 'projector',
	'screen' => DeviceType::SCREEN,
));

$content = '';
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	$content .= $row['hwname'] . "=beamer\n";
}

if (!empty($content)) {
	$tmpfile = '/tmp/bwlp-' . md5($content) . '.tar';
	if (!is_file($tmpfile) || !is_readable($tmpfile) || filemtime($tmpfile) + 86400 < time()) {
		if (file_exists($tmpfile)) {
			unlink($tmpfile);
		}
		try {
			$a = new PharData($tmpfile);
			$a->addFromString("/opt/openslx/beamergui/beamer.conf", $content);
			$file = $tmpfile;
		} catch (Exception $e) {
			EventLog::failure('Could not include beamer.conf in config.tgz', (string)$e);
			unlink($tmpfile);
		}
	} elseif (is_file($tmpfile) && is_readable($tmpfile)) {
		$file = $tmpfile;
	}
}
