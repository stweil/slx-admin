<?php

/*
 * Very cheap script to convert the jedec database from a text dump of the
 * official PDF to json. The regex abomination below has been kicked until
 * it worked on the version that was current as of April 2019. YMMV.
 * For input, download the PDF from https://www.jedec.org/system/files/docs/JEP106AY.pdf
 * and then copy/paste the contents into a plain text file called 'jedec'
 * (And pray it doesn't break if you don't use exactly the same PDF viewer and
 * text editor as I did - pdf.js and vim)
 */

$last = 0;
$index = 1;
$line = file_get_contents('jedec');
preg_match_all("/^\s*([1-9][0-9]?|1[01][0-9]|12[0-6])\s+([^\r\n]{2,9}(?:[a-z][^\r\n]{0,10}){1,3}[\r\n]?[^\r\n0]{0,31})(?:\s*[\r\n]\s|\s+)((?:[10]\s+){8})([0-9a-f]{2})\s*\$/sim", $line, $oout, PREG_SET_ORDER);
$output = [];
foreach ($oout as $out) {
	$id = (int)$out[1];
	$name = preg_replace("/[\s\r\n]+/ms", ' ', $out[2]);
	$bin = $out[3];
	$hex = $out[4];
	if ($id < $last) {
		$index++;
		echo "Now at bank $index\n";
	} elseif ($id > $last + 1) {
		echo "Skipped from $last to $id (THIS SHOULD NEVER HAPPEN)\n";
	}
	//echo "$id = $name ($bin) ($hex)\n";
	$last = $id;
	$output['bank' . $index]['id' . $id] = $name;
}

file_put_contents('jedec.json', json_encode($output));

