<?php

if (!RemoteReport::isReportingEnabled()) {
	Message::addInfo('statistics_reporting.remote-report-disabled', true);
}
