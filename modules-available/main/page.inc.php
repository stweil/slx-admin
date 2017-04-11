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
		foreach (Hook::load('main-warning') as $hook) {
			include $hook->file;
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
