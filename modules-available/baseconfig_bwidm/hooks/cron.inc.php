<?php

define('PROP_KEY_BWIDM', 'bwlp.bwidm.fetcher');
define('BWLP_SETTINGS_JSON', '/var/cache/slx-admin/baseconfig-bwidm_settings.json');

call_user_func(function()
{
	if (Property::get(PROP_KEY_BWIDM) !== false && file_exists(BWLP_SETTINGS_JSON))
		return;
	Property::set(PROP_KEY_BWIDM, true, 240);
	$ret = Download::asString('https://bwlp-masterserver.ruf.uni-freiburg.de/webif/pam.php', 10, $code);
	if (!preg_match_all('/^([^=]+)=/m', $ret, $out))
		return;
	$data = array("SLX_BWIDM_AUTH" => array(
			"catid" => "sysconfig",
			"defaultvalue" => "no",
			"permissions" => "2",
			"validator" => "list:no|selective|yes",
			"shadows" => array(
				"no" => array(
					"SLX_BWIDM_ORGS"
				),
				"yes" => array(
					"SLX_BWIDM_ORGS"
				)
			)
		),
		"SLX_BWIDM_ORGS" => array(
			"catid" => "sysconfig",
			"defaultvalue" => "",
			"permissions" => "2",
			"validator" => "multilist:" . implode('|', $out[1])
		)
	);
	if (!file_put_contents(BWLP_SETTINGS_JSON, json_encode($data))) {
		$error = error_get_last();
		EventLog::warning('Could not write bwIDM data to ' . BWLP_SETTINGS_JSON, $error['message']);
	}
});