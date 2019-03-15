<?php

if (Request::any('show') === 'svg') {
	$ret = PvsGenerator::generateSvg(Request::any('locationid', 0, 'int'),
		Request::any('machineuuid', false, 'string'));
	if ($ret === false) {
		Header('HTTP/1.1 404 Not Found');
		exit;
	}
	Header('Content-Type: image/svg+xml');
	die($ret);
}

die(PvsGenerator::generate());
