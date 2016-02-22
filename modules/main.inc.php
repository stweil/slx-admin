<?php

class Page_Main extends Page
{

	private $sysconfig;
	private $minilinux;
	private $vmstore;
	private $ipxe;
	private $delPending;

	protected function doPreprocess()
	{
		User::load();
		if (User::isLoggedIn()) {
			$this->sysconfig = !file_exists(CONFIG_HTTP_DIR . '/default/config.tgz');
			$this->minilinux = !file_exists(CONFIG_HTTP_DIR . '/default/kernel') || !file_exists(CONFIG_HTTP_DIR . '/default/initramfs-stage31') || !file_exists(CONFIG_HTTP_DIR . '/default/stage32.sqfs');
			$this->vmstore = !is_array(Property::getVmStoreConfig());
			$this->ipxe = true || !preg_match('/^\d+\.\d+\.\d+\.\d+$/', Property::getServerIp());
			Property::setNeedsSetup(($this->sysconfig || $this->minilinux || $this->vmstore || $this->ipxe) ? 1 : 0);
			$res = Database::queryFirst("SELECT Count(*) AS cnt FROM sat.imageversion WHERE deletestate = 'SHOULD_DELETE'");
			$this->delPending = isset($res['cnt']) ? $res['cnt'] : 0;
		}
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
			'vmstore' => $this->vmstore,
			'ipxe' => $this->ipxe,
			'delpending' => $this->delPending
		));
	}

	protected function doAjax()
	{
		User::isLoggedIn();
		die('Status: DB running');
	}

}
