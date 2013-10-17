<?php

require_once('inc/render.inc.php');
require_once('inc/user.inc.php');

class Menu
{

	public function loginPanel()
	{
		if (User::getName() === false) return Render::parse('menu-login');
		return Render::parse('menu-logout', array(
			'user' => User::getName(),
			'token' => Session::get('token')
		));
	}

}

