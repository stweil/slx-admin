<?php

class Page_Main extends Page
{

	protected function doPreprocess()
	{
		User::load();
	}

	protected function doRender()
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
		$sysconfig = !file_exists(CONFIG_HTTP_DIR . '/default/config.tgz');
		$minilinux = !file_exists(CONFIG_HTTP_DIR . '/default/kernel') || !file_exists(CONFIG_HTTP_DIR . '/default/initramfs-stage31') || !file_exists(CONFIG_HTTP_DIR . '/default/stage32.sqfs');
		Render::addTemplate('page-main', array('user' => User::getName(), 'ipxe' => $ipxe, 'sysconfig' => $sysconfig, 'minilinux' => $minilinux));
	}

}
