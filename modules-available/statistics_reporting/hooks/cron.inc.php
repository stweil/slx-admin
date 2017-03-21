<?php

if (RemoteReport::isReportingEnabled()) {
	$nextReporting = RemoteReport::getReportingTimestamp();

	// It's time to generate a new report
	if ($nextReporting <= time()) {
		RemoteReport::writeNextReportingTimestamp();

		$from = strtotime("-7 days", $nextReporting);
		$to = $nextReporting;

		$statisticsReport = json_encode(RemoteReport::generateReport($from, $to));

		$params = array("action" => "statistics", "data" => $statisticsReport);

		$result = Download::asStringPost(CONFIG_REPORTING_URL, $params, 30, $code);

		if ($code != 200) {
			EventLog::warning("Statistics Reporting failed: " . $code, $result);
		} else {
			EventLog::info('Statistics report sent to ' . CONFIG_REPORTING_URL);
		}
	}
}