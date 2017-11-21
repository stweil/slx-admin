<?php

$locations = ConfigHolder::get('SLX_LOCATIONS');
if ($locations === false) {
	$locationIds = [];
} else {
	$locationIds = explode(' ', $locations);
}
if (Exams::isInExamMode($locationIds, $lectureId, $autoLogin)) {
	ConfigHolder::add('SLX_EXAM', 'yes', 10000);
	if (strlen($lectureId) > 0) {
		ConfigHolder::add('SLX_EXAM_START', $lectureId, 10000);
	}
	if (strlen($autoLogin) > 0) {
		ConfigHolder::add('SLX_AUTOLOGIN', $autoLogin, 10000);
	}
	ConfigHolder::add('SLX_SYSTEMD_TARGET', 'exam-mode', 10000);
	ConfigHolder::add("SLX_PVS_HYBRID", false, 10000);
}
