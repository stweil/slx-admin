<?php

class Page_Session extends Page
{

	protected function doPreprocess()
	{
		User::load();
		if (Request::post('action') === 'login') {
			// Login - see if already logged in
			if (User::isLoggedIn()) // and then just redirect
				Util::redirect('?do=Main');
			// Else, try to log in
			if (User::login(Request::post('user'), Request::post('pass')))
				Util::redirect('?do=Main');
			// Login credentials wrong - delay and show error message
			sleep(1);
			Message::addError('loginfail');
		}
		if (Request::post('action') === 'logout') {
			// Log user out (or do nothing if not logged in)
			User::logout();
			Util::redirect('?do=Main');
		}

		if (User::isLoggedIn())
			Util::redirect('?do=Main');
	}

	protected function doRender()
	{
		Render::addTemplate('page-login');
	}

}
