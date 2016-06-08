<?php 
/* Hier kommt der Dozmod proxy hin */
require 'modules/locations/inc/location.inc.php';

define('LIST_URL', CONFIG_DOZMOD . '/vmchooser/list');
define('VMX_URL', CONFIG_DOZMOD . '/vmchooser/lecture');
$availableRessources = ['vmx', 'test', 'netrules'];


/* this script requires 2 (3 with implicit client ip) parameters 
 *
 * ressource    = vmx,...
 * lecture_uuid = client can choose
 **/


function println($str) { echo "$str\n"; }

/* return an array of lecutre uuids.
 * Parameter: an array with location Ids
 * Cacheable
 * */
function getLecturesForLocations($locationIds) {
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

function getVMX($lecture_uuid) {
    $url = VMX_URL . '/' . $lecture_uuid;
    $response = Download::asString($url, 60, $code);
    return $response;
}


// -----------------------------------------------------------------------------// 
$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}

/* request data, don't trust */
$request = [ 'ressource' => filter_var(strtolower(trim($_REQUEST['ressource'])), FILTER_SANITIZE_STRING),
             'lecture'   => filter_var(strtolower(trim($_REQUEST['lecture'])), FILTER_SANITIZE_STRING),
             'ip'        => $ip ]; 


/* lookup location id(s) */
$location_ids = Location::getFromIP($request['ip']);

/* lookup lecture uuids */
$lectures = getLecturesForLocations(array($location_ids));


/* validate request -------------------------------------------- */
/* check ressources */
if (!in_array($request['ressource'], $availableRessources)) {
    Util::traceError("unknown ressource: {$request['ressource']}");
}

/* check that the user requests a lecture that he is allowed to have */
if (!in_array($request['lecture'], $lectures)) {
    Util::traceError("client is not allowed to access this lecture: ${request['lecture']}");
}

if ($request['ressource'] === 'vmx') {
    echo getVMX($request['lecture']);
} else if ($request['ressource'] === 'test') {
    echo "Here's your special test data!";
} else {
    echo "I don't know how to give you that ressource";
}
