<?php

User::load();

function render_module()
{
	// Render::setTitle('abc');
	
	if (!User::isLoggedIn()) {
		Render::addTemplate('page-main-guest');
		return;
	}
	// Logged in here
	$ipxe = true;
	$file = CONFIG_IPXE_DIR . '/last-ip';
	if (file_exists($file)) {
		$last = file_get_contents($file);
		exec('/bin/ip a', $ips);
		foreach ($ips as $ip) {
			if (preg_match("#inet $last/\d+.*scope#", $ip)) $ipxe = false;
		}
	}
	Render::addTemplate('page-main', array('user' => User::getName(), 'ipxe' => $ipxe));
}

