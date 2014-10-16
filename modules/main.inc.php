<?php

class Page_Main extends Page
{

	private $sysconfig;
	private $minilinux;
	private $vmstore;

	protected function doPreprocess()
	{
		User::load();
		$this->sysconfig = !file_exists(CONFIG_HTTP_DIR . '/default/config.tgz');
		$this->minilinux = !file_exists(CONFIG_HTTP_DIR . '/default/kernel') || !file_exists(CONFIG_HTTP_DIR . '/default/initramfs-stage31') || !file_exists(CONFIG_HTTP_DIR . '/default/stage32.sqfs');
		$this->vmstore = !is_array(Property::getVmStoreConfig());
		Property::setNeedsSetup(($this->sysconfig || $this->minilinux || $this->vmstore) ? 1 : 0);
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

		Render::addTemplate('page-main', array(
			'user' => User::getName(),
			'sysconfig' => $this->sysconfig,
			'minilinux' => $this->minilinux,
			'vmstore' => $this->vmstore
		));
	}

}
