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
	Util::redirect('?do=session&action=fail');
}

if ($_REQUEST['action'] === 'logout') {
	// Log user out (or do nothing if not logged in)
	exit(0);
}

function render_module()
{
	if (!isset($_GET['action'])) Util::traceError('No action on render');
	if ($_GET['action'] === 'login') {
		Render::setTitle('Anmelden');
		Render::addTemplate('page-login');
		return;
	}
	if ($_GET['action'] === 'fail') {
		Render::setTitle('Fehler');
		Render::addError('Benutzer oder Passwort falsch');
		return;
	}
}

