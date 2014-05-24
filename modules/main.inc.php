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
		$ipxe = (Property::getServerIp() !== Property::getIPxeIp());
		$sysconfig = !file_exists(CONFIG_HTTP_DIR . '/default/config.tgz');
		$minilinux = !file_exists(CONFIG_HTTP_DIR . '/default/kernel') || !file_exists(CONFIG_HTTP_DIR . '/default/initramfs-stage31') || !file_exists(CONFIG_HTTP_DIR . '/default/stage32.sqfs');
		Render::addTemplate('page-main', array('user' => User::getName(), 'ipxe' => $ipxe, 'sysconfig' => $sysconfig, 'minilinux' => $minilinux));
	}

}
