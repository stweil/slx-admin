<?php
/* small API server that acts as a proxy to the dozmod server.
* To reduce the number of requests and connections to dozmod-server, results
* gets cached into a file cache.
*
* Required Configuration:
* CONFIG_DOZMOD_EXPIRE: Expiration time in seconds for the cache
* CONFIG_DOZMOD_URL: URL to the dozmod server
*
**/


if (!Module::isAvailable('locations')) {
	die('require locations module');
}


define('LIST_URL', CONFIG_DOZMOD_URL . '/vmchooser/list');
define('VMX_URL', CONFIG_DOZMOD_URL . '/vmchooser/lecture');
$availableRessources = ['list', 'vmx', 'test', 'netrules'];

/* BEGIN: A simple caching mechanism ---------------------------- */

function cache_hash($obj)
{
	return md5(serialize($obj));
}

function cache_key_to_filename($key)
{
	return "/tmp/bwlp-slxadmin-cache-$key";
}

function cache_put($key, $value)
{
	$filename = cache_key_to_filename($key);
	file_put_contents($filename, $value);
}

function cache_has($key)
{
	$filename = cache_key_to_filename($key);
	$mtime = @filemtime($filename);

	if ($mtime === false) {
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
		exit;
	}
	error_log('Cannot passthrough cache file ' . $filename);
}

/* END: Cache ---------------------------------------------------- */


/* this script requires 2 (3 with implicit client ip) parameters
*
* resource     = vmx,...
* lecture_uuid = client can choose
**/


/**
 * Takes raw lecture list xml, returns array of uuids.
 *
 * @param string $responseXML XML from dozmod server
 * @return array list of UUIDs, false on error
 */
function xmlToLectureIds($responseXML)
{
	$xml = new SimpleXMLElement($responseXML);
	if (!isset($xml->eintrag))
		return [];

	$uuids = [];
	foreach ($xml->eintrag as $e) {
		$uuids[] = strval($e->uuid['param'][0]);
	}
	return $uuids;
}

/** Caching wrapper around _getLecturesForLocations() */
function getListForLocations($locationIds, $raw)
{
	/* if in any of the locations there is an exam active, consider the client
		 to be in "exam-mode" and only offer him exams (no lectures) */
	$key = 'lectures_' . cache_hash($locationIds);
	$examMode = false;
	if (Module::isAvailable('exams')) {
		$examMode = Exams::isInExamMode($locationIds);
		if ($examMode) {
			$key .= '_exams';
		}
	}
	$rawKey = $key . '_raw';
	if ($raw) {
		Header('Content-Type: text/xml; charset=utf-8');
		if (cache_has($rawKey)) {
			cache_get_passthru($rawKey);
		}
	} elseif (cache_has($key)) {
		return unserialize(cache_get($key));
	}
	// Not in cache
	$url = LIST_URL . "?locations=" . implode('%20', $locationIds);
	if ($examMode) {
		$url .= '&exams';
	}
	$value = Download::asString($url, 60, $code);
	if ($value === false)
		return false;
	cache_put($rawKey, $value);
	$list = xmlToLectureIds($value);
	cache_put($key, serialize($list));
	if ($raw) {
		die($value);
	}
	return $list;
}

function getLectureUuidsForLocations($locationIds)
{
	return getListForLocations($locationIds, false);
}

function outputLectureXmlForLocation($locationIds)
{
	return getListForLocations($locationIds, true);
}

function _getVMX($lecture_uuid)
{
	$url = VMX_URL . '/' . $lecture_uuid;
	$response = Download::asString($url, 60, $code);
	return $response;
}

/** Caching wrapper around _getVMX() **/
function outputVMX($lecture_uuid)
{
	$key = 'vmx_' . $lecture_uuid;
	if (cache_has($key)) {
		cache_get_passthru($key);
	} else {
		$value = _getVMX($lecture_uuid);
		if ($value === false)
			return false;
		cache_put($key, $value);
		die($value);
	}
}

function fatalDozmodUnreachable()
{
	Header('HTTP/1.1 504 Gateway Timeout');
	die('Resource not available');
}

function readLectureParam()
{
	global $location_ids;
	$lecture = Request::get('lecture', false, 'string');
	if ($lecture === false) {
		Header('HTTP/1.1 400 Bad Request');
		die('Missing lecture UUID');
	}
	$lectures = getLectureUuidsForLocations($location_ids);
	if ($lectures === false) {
		fatalDozmodUnreachable();
	}
	/* check that the user requests a lecture that he is allowed to have */
	if (!in_array($lecture, $lectures)) {
		Header('HTTP/1.1 403 Forbidden');
		die("You don't have permission to access this lecture");
	}
	return $lecture;
}


// -----------------------------------------------------------------------------//
/* request data, don't trust */
$resource = Request::get('resource', false, 'string');

if ($resource === false) {
	Util::traceError("you have to specify the 'resource' parameter");
}

if (!in_array($resource, $availableRessources)) {
	Util::traceError("unknown resource: $resource");
}

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}


/* lookup location id(s) */
$location_ids = Location::getFromIp($ip);
$location_ids = Location::getLocationRootChain($location_ids);

if ($resource === 'vmx') {
	$lecture = readLectureParam();
	outputVMX($lecture);
	// outputVMX does not return on success
	fatalDozmodUnreachable();
}

if ($resource === 'list') {
	outputLectureXmlForLocation($location_ids);
	// Won't return on success...
	fatalDozmodUnreachable();
}

Header('HTTP/1.1 400 Bad Request');
die("I don't know how to give you that resource");
