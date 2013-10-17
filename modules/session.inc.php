<?php

if (!isset($_REQUEST['action'])) Util::traceError('No action on module init');

User::load();

if (isset($_POST['action']) && $_POST['action'] === 'login') {
	// Login - see if already logged in
	if (User::isLoggedIn()) {
		Util::redirect('?do=main');
	}
	// Else, try to log in
	if (User::login($_POST['user'], $_POST['pass'])) {
		Util::redirect('?do=main');
	}
	// Login credentials wrong
	Message::addError('loginfail');
}

if ($_REQUEST['action'] === 'logout') {
	if (Util::verifyToken()) {
		// Log user out (or do nothing if not logged in)
		User::logout();
		Util::redirect('?do=main');
	}
}

function render_module()
{
	if ($_REQUEST['action'] === 'login') {
		Render::setTitle('Anmelden');
		Render::addTemplate('page-login');
		return;
	}
}

