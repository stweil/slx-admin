<?php

if (isset($configVars["SLX_LOCATIONS"])) {
	$locationIds = explode(' ', $configVars["SLX_LOCATIONS"]);
} else {
	$locationIds = array();
}
if (Exams::isInExamMode($locationIds, $lectureId, $autoLogin)) {
	$configVars['SLX_EXAM'] = 'yes';
	if (strlen($lectureId) > 0) {
		$configVars['SLX_EXAM_START'] = $lectureId;
	}
	if (strlen($autoLogin) > 0) {
		$configVars['SLX_AUTOLOGIN'] = $autoLogin;
	}
}
