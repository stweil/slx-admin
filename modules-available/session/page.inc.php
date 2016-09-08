<?php

class Page_Session extends Page
{

	protected function doPreprocess()
	{
		User::load();
		$action = Request::post('action');
		if ($action === 'login') {
			// Login - see if already logged in
			if (User::isLoggedIn()) // and then just redirect
				Util::redirect('?do=main');
			// Else, try to log in
			if (User::login(Request::post('user'), Request::post('pass')))
				Util::redirect('?do=main');
			// Login credentials wrong - delay and show error message
			sleep(1);
			Message::addError('loginfail');
		}
		if ($action === 'logout') {
			// Log user out (or do nothing if not logged in)
			User::logout();
			Util::redirect('?do=main');
		}
		if ($action === 'changepw') {
			if (!User::isLoggedIn()) {
				Util::redirect('?do=main');
			}
			// Now check if the user supplied the corrent current password, and the new password twice
			$old = Request::post('old', false, 'string');
			$new = Request::post('newpass1', false, 'string');
			if ($old === false || $new === false) {
				Message::addError('main.empty-field');
				Util::redirect('?do=session');
			}
			if (!User::testPassword(User::getId(), $old)) {
				sleep(1);
				Message::addError('wrong-password');
				Util::redirect('?do=session');
			}
			if (strlen($new) < 4) {
				Message::addError('pass-too-short');
				Util::redirect('?do=session');
			}
			if ($new !== Request::post('newpass2', false, 'string')) {
				Message::addError('adduser.password-mismatch');
				Util::redirect('?do=session');
			}
			if (User::updatePassword($new)) {
				Message::addSuccess('password-changed');
			} else {
				Message::addWarning('password-unchanged');
			}
			Util::redirect('?do=session');
		}
	}

	protected function doRender()
	{
		if (User::isLoggedIn()) {
			Render::addTemplate('change-password');
		} else {
			Render::addTemplate('page-login');
		}
	}

}
