<?php

if (RemoteReport::isReportingEnabled()) {
	$nextReporting = RemoteReport::getReportingTimestamp();

	// It's time to generate a new report
	while ($nextReporting <= time()) {
		RemoteReport::writeNextReportingTimestamp();

		$to = $nextReporting;

		$statisticsReport = json_encode(RemoteReport::generateReport($to));

		$params = array("action" => "statistics", "data" => $statisticsReport);

		$result = Download::asStringPost(CONFIG_REPORTING_URL, $params, 30, $code);

		if ($code != 200) {
			EventLog::warning("Statistics Reporting failed: " . $code, $result);
		} else {
			EventLog::info('Statistics report sent to ' . CONFIG_REPORTING_URL);
		}
		$nextReporting = strtotime("+7 days", $nextReporting);
	}
}