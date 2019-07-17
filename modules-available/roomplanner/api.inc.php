<?php

if (Request::any('show') === 'svg') {
	$ret = PvsGenerator::generateSvg(Request::any('locationid', false, 'int'),
		Request::any('machineuuid', false, 'string'),
		Request::any('rotate', 0, 'int'),
		Request::any('scale', 1, 'float'));
	if ($ret === false) {
		Header('HTTP/1.1 404 Not Found');
		exit;
	}
	Header('Content-Type: image/svg+xml');
	die($ret);
}

die(PvsGenerator::generate());
