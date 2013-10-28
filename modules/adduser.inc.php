<?php

User::load();

if (isset($_POST['action']) && $_POST['action'] === 'adduser') {
	// Check required fields
	if (empty($_POST['user']) || empty($_POST['pass1']) || empty($_POST['pass2']) || empty($_POST['fullname']) || empty($_POST['phone']) || empty($_POST['email'])) {
		Message::addError('empty-field');
	} elseif ($_POST['pass1'] !== $_POST['pass2']) {
		Message::addError('password-mismatch');
	} else {
		$salt = substr(str_replace('+', '.', base64_encode(pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()))), 0, 22);
		$data = array(
			'user'       => $_POST['user'],
			'pass'       => crypt($_POST['pass1'], '$6$' . $salt),
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
	if (isset($adduser_success)) {
		Message::addInfo('adduser-success');
		return;
	}
	if (Database::queryFirst('SELECT userid FROM user LIMIT 1') !== false) {
		Message::addError('adduser-disabled');
	} else {
		Render::setTitle('Benutzer anlegen');
		Render::addTemplate('page-adduser', $_POST);
	}
}

