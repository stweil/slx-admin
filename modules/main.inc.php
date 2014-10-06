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
			Render::addTemplate('page-main-guest', array(
				'register' => (Database::queryFirst('SELECT userid FROM user LIMIT 1') === false)
			));
			return;
		}
		// Logged in here
		$sysconfig = !file_exists(CONFIG_HTTP_DIR . '/default/config.tgz');
		$minilinux = !file_exists(CONFIG_HTTP_DIR . '/default/kernel') || !file_exists(CONFIG_HTTP_DIR . '/default/initramfs-stage31') || !file_exists(CONFIG_HTTP_DIR . '/default/stage32.sqfs');
		$vmstore = !is_array(Property::getVmStoreConfig());
		Render::addTemplate('page-main', array(
			'user' => User::getName(),
			'sysconfig' => $sysconfig,
			'minilinux' => $minilinux,
			'vmstore' => $vmstore
		));
	}

}
