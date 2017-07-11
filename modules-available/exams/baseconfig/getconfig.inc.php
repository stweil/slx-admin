<?php

$locations = ConfigHolder::get('SLX_LOCATIONS');
if ($locations === false) {
	$locationIds = [];
} else {
	$locationIds = explode(' ', $locations);
}
if (Exams::isInExamMode($locationIds, $lectureId, $autoLogin)) {
	ConfigHolder::add('SLX_EXAM', 'yes', 100000);
	if (strlen($lectureId) > 0) {
		ConfigHolder::add('SLX_EXAM_START', $lectureId, 100000);
	}
	if (strlen($autoLogin) > 0) {
		ConfigHolder::add('SLX_AUTOLOGIN', $autoLogin, 100000);
	}
}
