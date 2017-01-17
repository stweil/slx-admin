<?php

$nextReporting = Property::get("nextReporting", 0);
$time = time();

$allowReport = Property::get("reportingStatus", "on") == "on";

if ($nextReporting < $time && $allowReport) {

	Property::set("nextReporting", strtotime("Sunday 23:59:59"));

	GetData::$from = strtotime("Monday last week");
	GetData::$to = strtotime("Sunday last week 23:59:59");

	$data = array_merge(GetData::total(true), array('perLocation' => array(), 'perClient' => array(), 'perUser' => array(), 'perVM' => array()));
	$data['perLocation'] = GetData::perLocation(true);
	$data['perClient'] = GetData::perClient(true);
	$data['perUser'] = GetData::perUser(true);
	$data['perVM'] = GetData::perVM(true);


	$statisticsReport = json_encode($data);

	$url = CONFIG_REPORTING_URL;

	$curl = curl_init($url);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_HTTPHEADER,	array("Content-type: application/json"));
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $statisticsReport);

	$json_response = curl_exec($curl);

	curl_close($curl);
}
