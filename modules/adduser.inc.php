<?php

User::load();

if (isset($_POST['action']) && $_POST['action'] === 'adduser') {
	// Check required fields
	if (empty($_POST['user']) || empty($_POST['pass1']) || empty($_POST['pass2']) || empty($_POST['fullname']) || empty($_POST['phone']) || empty($_POST['email'])) {
		Message::addError('empty-field');
		Util::redirect('?do=AddUser');
	} elseif ($_POST['pass1'] !== $_POST['pass2']) {
		Message::addError('password-mismatch');
		Util::redirect('?do=AddUser');
	} elseif (Database::queryFirst('SELECT userid FROM user LIMIT 1') !== false) {
		Message::addError('adduser-disabled');
		Util::redirect('?do=Session&action=login');
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
		// Make it superadmin if first user. This method sucks as it's a race condition but hey...
		$ret = Database::queryFirst('SELECT Count(*) AS num FROM user');
		if ($ret !== false && $ret['num'] == 1) {
			Database::exec('UPDATE user SET permissions = 1');
		}
		Message::addInfo('adduser-success');
		Util::redirect('?do=Session&action=login');
	}
}

function render_module()
{
	// No user was added, check if current user is allowed to add a new user
	// Currently you can only add users if there is no user yet. :)
	if (Database::queryFirst('SELECT userid FROM user LIMIT 1') !== false) {
		Message::addError('adduser-disabled');
	} else {
		Render::setTitle('Benutzer anlegen');
		Render::addTemplate('page-adduser', $_POST);
	}
}

