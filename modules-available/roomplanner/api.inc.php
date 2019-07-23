<?php

// SVG
if (Request::any('show') === 'svg') {
	$ret = PvsGenerator::generateSvg(Request::any('locationid', false, 'int'),
		Request::any('machineuuid', false, 'string'),
		Request::any('rotate', 0, 'int'),
		Request::any('scale', 1, 'float'));
	if ($ret === false) {
		if (Request::any('fallback', 0, 'int') === 0) {
			Header('HTTP/1.1 404 Not Found');
			exit;
		}
		$ret = <<<EOF
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 64 64" height="64" width="64">
  <g>
	 <path d="M 0,0 64,64 Z" style="stroke:#ff0000;stroke-width:5" />
	 <path d="M 0,64 64,0 Z" style="stroke:#ff0000;stroke-width:5" />
  </g>
</svg>
EOF;
	}
	Header('Content-Type: image/svg+xml');
	die($ret);
}

// PVS.ini
die(PvsGenerator::generate());
