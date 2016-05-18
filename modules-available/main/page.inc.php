<?php

class Page_Main extends Page
{

	protected function doPreprocess()
	{
		User::load();
	}

	protected function doRender()
	{
		if (!User::isLoggedIn()) {
			Render::addTemplate('page-main-guest', array(
				'register' => (Database::queryFirst('SELECT userid FROM user LIMIT 1') === false)
			));
			return;
		}
		// Logged in here
		
		Render::addTemplate('page-main', array(
			'user' => User::getName()
		));

		// Warnings
		$needSetup = false;
		foreach (glob('modules/*/hooks/main-warning.inc.php') as $file) {
			preg_match('#^modules/([^/]+)/#', $file, $out);
			if (!Module::isAvailable($out[1]))
				continue;
			include $file;
		}

		// Update warning state
		Property::setNeedsSetup($needSetup ? 1 : 0);
	}

	protected function doAjax()
	{
		User::isLoggedIn();
		die('Status: DB running');
	}

}
