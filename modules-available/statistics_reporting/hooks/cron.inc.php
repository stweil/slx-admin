<?php

$nextReporting = Property::get("nextReporting", 0);
$time = time();

$allowReport = Property::get("reportingStatus", "on") == "on";

if ($nextReporting < $time && $allowReport) {

	Property::set("nextReporting", strtotime("Sunday 23:59:59"));

	GetData::$from = strtotime("last sunday - 6 days");
	GetData::$to = strtotime("last sunday 23:59:59");
	GetData::$salt = bin2hex(random_bytes(20));

	$data = array_merge(GetData::total(true), array('perLocation' => array(), 'perClient' => array(), 'perUser' => array(), 'perVM' => array()));
	$data['perLocation'] = GetData::perLocation(true);
	$data['perClient'] = GetData::perClient(true);
	$data['perUser'] = GetData::perUser(true);
	$data['perVM'] = GetData::perVM(true);


	$statisticsReport = json_encode($data);

	$params = array("action" => "statistics", "data" => $statisticsReport);

	Download::asStringPost(CONFIG_REPORTING_URL, $params, 300, $code);

	if ($code != 200) {
		EventLog::warning("Statistics Reporting: ".$code);
	}
}
