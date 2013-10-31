<?php

User::load();

if (isset($_POST['action']) && $_POST['action'] === 'adduser') {
	// Check required fields
	if (empty($_POST['user']) || empty($_POST['pass1']) || empty($_POST['pass2']) || empty($_POST['fullname']) || empty($_POST['phone']) || empty($_POST['email'])) {
		Message::addError('empty-field');
		Util::redirect('?do=adduser');
	} elseif ($_POST['pass1'] !== $_POST['pass2']) {
		Message::addError('password-mismatch');
		Util::redirect('?do=adduser');
	} else {
		$data = array(
			'user'       => $_POST['user'],
			'pass'       => Crypto::hash6($_POST['pass1']),
			'fullname'   => $_POST['fullname'],
			'phone'      => $_POST['phone'],
			'email'      => $_POST['email'],
		);
		if (strlen($data['pass']) < 50) Util::traceError('Error hashing password using SHA-512');
		if (Database::exec('INSERT INTO user SET login = :user, passwd = :pass, fullname = :fullname, phone = :phone, email = :email', $data) != 1) {
			Util::traceError('Could not create new user in DB');
		}
		$adduser_success = true;
	}
}

function render_module()
{
	// A user was added. Show success message and bail out
	if (isset($adduser_success)) {
		Message::addInfo('adduser-success');
		return;
	}
	// No user was added, check if current user is allowed to add a new user
	// Currently you can only add users if there is no user yet. :)
	if (Database::queryFirst('SELECT userid FROM user LIMIT 1') !== false) {
		Message::addError('adduser-disabled');
	} else {
		Render::setTitle('Benutzer anlegen');
		Render::addTemplate('page-adduser', $_POST);
	}
}

