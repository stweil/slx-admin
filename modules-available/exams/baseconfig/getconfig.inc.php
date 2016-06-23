<?php

if (isset($configVars["SLX_LOCATIONS"])) {
	$locationIds = explode(' ', $configVars["SLX_LOCATIONS"]);
	if (Exams::isInExamMode($locationIds)) {
		$configVars['SLX_EXAM'] = 'yes';
	}
}
