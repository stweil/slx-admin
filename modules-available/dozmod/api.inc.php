<?php
/* small API server that acts as a proxy to the dozmod server.
 * To reduce the number of requests and connections to dozmod-server, results
 * gets cached into a file cache.
 *
 * Required Configuration:
 * CONFIG_DOZMOD_EXPIRE: Expiration time in seconds for the cache
 * CONFIG_DOZMOD: URL to the dozmod server
 * 
 **/

require 'modules/locations/inc/location.inc.php';


define('LIST_URL', CONFIG_DOZMOD . '/vmchooser/list');
define('VMX_URL', CONFIG_DOZMOD . '/vmchooser/lecture');
$availableRessources = ['vmx', 'test', 'netrules'];

/* BEGIN: A simple caching mechanism ---------------------------- */

function cache_hash($obj)
{
	return md5(serialize($obj));
}

function cache_key_to_filename($key)
{
	return "/tmp/bwlp-slxadmin-cache-$key"; // TODO: hash
}

function cache_put($key, $value)
{
	$filename = cache_key_to_filename($key);
	file_put_contents($filename, $value);
}

function cache_has($key)
{
	$filename = cache_key_to_filename($key);
	$mtime = filemtime($filename);

	if (!$mtime) {
		return false; // cache miss
	}
	if (time() - $mtime > CONFIG_DOZMOD_EXPIRE) {
		return false;
	} else {
		return true;
	}
}


function cache_get($key)
{
	$filename = cache_key_to_filename($key);
	return file_get_contents($filename);
}

/* good for large binary files */
function cache_get_passthru($key)
{
	$filename = cache_key_to_filename($key);
	$fp = fopen($filename, "r");
	if ($fp) {
		fpassthru($fp);
	} else {
		Util::traceError("cannot open file");
	}
}

/* END: Cache ---------------------------------------------------- */


/* this script requires 2 (3 with implicit client ip) parameters 
 *
 * resource    = vmx,...
 * lecture_uuid = client can choose
 **/


function println($str) { echo "$str\n"; }

/* return an array of lecutre uuids.
 * Parameter: an array with location Ids
 * */
function _getLecturesForLocations($locationIds)
{
	$ids = implode('%20', $locationIds);
	$url = LIST_URL . "?locations=$ids";
	$responseXML = Download::asString($url, 60, $code);
	$xml = new SimpleXMLElement($responseXML);

	$uuids = [];
	foreach ($xml->eintrag as $e) {
		$uuids[] = strval($e->uuid['param'][0]);
	}
	return $uuids;
}

/** Caching wrapper around _getLecturesForLocations() */
function getLecturesForLocations($locationIds)
{
	$key = 'lectures_' . cache_hash($locationIds);
	if (cache_has($key)) {
		return unserialize(cache_get($key));
	} else {
		$value = _getLecturesForLocations($locationIds);
		cache_put($key, serialize($value));
		return $value;
	}
}

function _getVMX($lecture_uuid)
{
	$url = VMX_URL . '/' . $lecture_uuid;
	$response = Download::asString($url, 60, $code);
	return $response;
}

/** Caching wrapper around _getVMX() **/
function getVMX($lecture_uuid)
{
	$key = 'vmx_' . $lecture_uuid;
	if (cache_has($key)) {
		cache_get_passthru($key);
	} else {
		$value = _getVMX($lecture_uuid);
		cache_put($key, $value);
		return $value;
	}
}


// -----------------------------------------------------------------------------// 
$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}

/* request data, don't trust */
$request = ['resource' => filter_var(strtolower(trim($_REQUEST['resource'])), FILTER_SANITIZE_STRING),
	'lecture' => filter_var(strtolower(trim($_REQUEST['lecture'])), FILTER_SANITIZE_STRING),
	'ip' => $ip];


/* lookup location id(s) */
$location_ids = Location::getFromIP($request['ip']);

/* lookup lecture uuids */
$lectures = getLecturesForLocations(array($location_ids));

/* validate request -------------------------------------------- */
/* check resources */
if (!in_array($request['resource'], $availableRessources)) {
	Util::traceError("unknown resource: {$request['resource']}");
}

/* check that the user requests a lecture that he is allowed to have */
if (!in_array($request['lecture'], $lectures)) {
	Util::traceError("client is not allowed to access this lecture: ${request['lecture']}");
}

if ($request['resource'] === 'vmx') {
	echo getVMX($request['lecture']);
} else if ($request['resource'] === 'test') {
	echo "Here's your special test data!";
} else {
	echo "I don't know how to give you that resource";
}
