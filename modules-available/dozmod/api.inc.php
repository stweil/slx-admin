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
$availableRessources = ['list', 'vmx', 'netrules', 'runscript', 'metadata', 'netshares'];

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
	$now = time();
	if ($now < $mtime || $now - $mtime > CONFIG_DOZMOD_EXPIRE) {
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
		if (isset($e->uuid) && isset($e->uuid['param']) && isset($e->uuid['param'][0])) {
			$uuids[] = strval($e->uuid['param'][0]);
		}
	}
	return $uuids;
}

function sendExamModeMismatch()
{
	Header('Content-Type: text/xml; charset=utf-8');
	echo
	<<<BLA
	<settings>
		<eintrag>
			<image_name param="null"/>
			<priority param="100"/>
			<creator param="Ernie Esslingen"/>
			<short_description param="Klausurmodus geändert, bitte PC neustarten"/>
			<long_description param="Der Klausurmodus wurde ein- oder ausgeschaltet, bitte starten Sie den PC neu"/>
			<uuid param="exam-mode-warning"/>
			<virtualmachine param="exam-mode-warning"/>
			<os param="debian8"/>
			<virtualizer_name param="null"/>
			<os_name param="null"/>
			<for_location param="0"/>
			<is_template param="0"/>
		</eintrag>
		<eintrag>
			<image_name param="null"/>
			<priority param="200"/>
			<creator param="Ernie Esslingen"/>
			<short_description param="Exam mode changed, please reboot PC"/>
			<long_description param="Exam mode has been activated or deactivated since this PC was booted; please reboot the PC"/>
			<uuid param="exam-mode-warning"/>
			<virtualmachine param="exam-mode-warning"/>
			<os param="debian8"/>
			<virtualizer_name param="null"/>
			<os_name param="null"/>
			<for_location param="0"/>
			<is_template param="0"/>
		</eintrag>
	</settings>
BLA;
	exit(0);
}

/** Caching wrapper around _getLecturesForLocations() */
function getListForLocations($locationIds, $raw)
{
	/* if in any of the locations there is an exam active, consider the client
		 to be in "exam-mode" and only offer him exams (no lectures) */
	$key = 'lectures_' . cache_hash($locationIds);
	$examMode = Request::get('exams', 'normal-mode', 'string') !== 'normal-mode';
	$clientServerMismatch = false;
	if (Module::isAvailable('exams')) {
		// If we have the exam mode module, we can enforce a server side check and make sure it agrees with the client
		$serverExamMode = Exams::isInExamMode($locationIds);
		if ($raw) {
			$clientServerMismatch = ($serverExamMode !== $examMode);
		}
		$examMode = $serverExamMode;
	}
	// Only enforce exam mode validity check if the client requests the raw xml data
	if ($clientServerMismatch) {
		sendExamModeMismatch(); // does not return
	}
	// Proceed normally from here on
	if ($examMode) {
		$key .= '_exams';
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
	if ($value === false || $code < 200 || $code > 299)
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

function _getVmData($lecture_uuid, $subResource = false)
{
	$url = VMX_URL . '/' . $lecture_uuid;
	if ($subResource !== false) {
		$url .= '/' . $subResource;
	}
	$response = Download::asString($url, 60, $code);
	if ($code < 200 || $code > 299)
		return (int)$code;
	return $response;
}

/** Caching wrapper around _getVmData() **/
function outputResource($lecture_uuid, $resource)
{
	if ($resource === 'metadata') {
		// HACK: config.tgz is compressed, don't use gzip output handler
		@ob_end_clean();
		Header('Content-Type: application/gzip');
	} else {
		Header('Content-Type: text/plain; charset=utf-8');
	}
	$key = $resource . '_' . $lecture_uuid;
	if (cache_has($key)) {
		if ($resource === 'metadata' || $resource === 'vmx') {
			// HACK HACK HACK: Update launch counter as it was cached,
			// otherwise dmsd would take care of increasing it...
			Database::exec("UPDATE sat.lecture SET usecount = usecount + 1 WHERE lectureid = :lectureid",
				['lectureid' => $lecture_uuid], true);
		}
		cache_get_passthru($key);
	} else {
		$value = _getVmData($lecture_uuid, $resource);
		if ($value === false)
			return false;
		if (is_int($value)) {
			http_response_code($value);
			exit;
		}
		cache_put($key, $value);
		die($value);
	}
	return false;
}

function fatalDozmodUnreachable()
{
	Header('HTTP/1.1 504 Gateway Timeout');
	die('DMSD currently not available');
}

function readLectureParam($locationIds)
{
	$lecture = Request::get('lecture', false, 'string');
	if ($lecture === false) {
		Header('HTTP/1.1 400 Bad Request');
		die('Missing lecture UUID');
	}
	$lectures = getLectureUuidsForLocations($locationIds);
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
	Header('HTTP/1.1 400 Bad Request');
	die("unknown resource: $resource");
}

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}


/* lookup location id(s) */
$location_ids = Location::getFromIp($ip, true);
$location_ids = Location::getLocationRootChain($location_ids);

if ($resource === 'list') {
	outputLectureXmlForLocation($location_ids);
	// Won't return on success...
	fatalDozmodUnreachable();
} else {
	$lecture = readLectureParam($location_ids);
	outputResource($lecture, $resource);
	fatalDozmodUnreachable();
}

Header('HTTP/1.1 400 Bad Request');
die("I don't know how to give you that resource");
