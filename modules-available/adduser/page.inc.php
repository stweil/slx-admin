<?php

class Page_AddUser extends Page
{

	protected function doPreprocess()
	{
		User::load();

		$action = Request::post(('action'), false, 'string');

		if ($action === 'adduser') {
			$this->addUser();
		} elseif ($action === 'edituser') {
			$this->editUser();
		} elseif ($action === 'deleteuser') {
			$this->deleteUser();
		}
		if (Request::isPost()) {
			Util::redirect('?do=adduser');
		}
	}

	private function addUser()
	{
		// Check required fields
		$login = Request::post('login', '', 'string');
		$pass1 = Request::post('pass1', '', 'string');
		$pass2 = Request::post('pass2', '', 'string');
		$fullname = Request::post('fullname', '', 'string');
		$phone = Request::post('phone', '', 'string');
		$email = Request::post('email', '', 'string');
		if (empty($login) || empty($pass1) || empty($pass2) || empty($fullname)) {
			Message::addError('main.empty-field');
			return;
		} elseif ($pass1 !== $pass2) {
			Message::addError('password-mismatch');
			return;
		} else {
			if (Database::queryFirst('SELECT userid FROM user LIMIT 1') !== false) {
				User::assertPermission('user.add');
			}
			$data = array(
				'login' => $login,
				'pass' => Crypto::hash6($pass1),
				'fullname' => $fullname,
				'phone' => $phone,
				'email' => $email,
			);
			Database::exec('INSERT INTO user SET login = :login, passwd = :pass, fullname = :fullname, phone = :phone, email = :email', $data);
			$id = Database::lastInsertId();
			// Make it superadmin if first user. This method sucks as it's a race condition but hey...
			$ret = Database::queryFirst('SELECT Count(*) AS num FROM user');
			if ($ret !== false && $ret['num'] == 1) {
				$ret = Database::exec('UPDATE user SET permissions = 1, userid = 1 WHERE userid = :id', ['id' => $id], true);
				if ($ret !== false) {
					EventLog::clear();
				}
				EventLog::info('Created first user ' . $login);
			} else {
				EventLog::info(User::getName() . ' created user ' . $login);
			}
			Message::addInfo('adduser-success');
			return;
		}
	}

	private function editUser()
	{
		User::assertPermission('user.edit');
		$userid = Request::post('userid', false, 'int');
		if ($userid === false) {
			Message::addError('main.parameter-missing', 'userid');
			return;
		}
		$user = Database::queryFirst('SELECT userid, login, fullname, phone, email
					FROM user WHERE userid = :userid', compact('userid'));
		if ($user === false) {
			Message::addError('user-not-found', $userid);
			return;
		}
		// Check required fields
		$login = Request::post('login', '', 'string');
		$pass1 = Request::post('pass1', '', 'string');
		$pass2 = Request::post('pass2', '', 'string');
		$fullname = Request::post('fullname', '', 'string');
		$phone = Request::post('phone', '', 'string');
		$email = Request::post('email', '', 'string');
		if (empty($login) || empty($fullname)) {
			Message::addError('main.empty-field');
		} elseif (!(empty($pass1) && empty($pass2)) && $pass1 !== $pass2) {
			Message::addError('password-mismatch');
		} else {
			$data = array(
				'login' => $login,
				'fullname' => $fullname,
				'phone' => $phone,
				'email' => $email,
				'userid' => $userid,
			);
			$ret = Database::exec('UPDATE user SET login = :login, fullname = :fullname, phone = :phone, email = :email WHERE userid = :userid', $data, true);
			if ($ret === false) {
				Message::addError('db-error', Database::lastError());
			} else {
				if ($ret > 0) {
					Message::addSuccess('user-edited');
				}
				if (!empty($pass1)) {
					$data = [
						'pass' => Crypto::hash6($pass1),
						'userid' => $userid,
					];
					Database::exec('UPDATE user SET passwd = :pass WHERE userid = :userid', $data);
					Message::addSuccess('password-changed');
				}
			}
		}
		Util::redirect('?do=adduser&show=edituser&userid=' . $userid);
	}

	private function deleteUser()
	{
		User::assertPermission('user.remove');
		$userid = Request::post('userid', false, 'int');
		if ($userid === false) {
			Message::addError('main.parameter-missing', 'userid');
			return;
		}
		//\\
		$user = Database::queryFirst('SELECT userid, login, fullname, phone, email
					FROM user WHERE userid = :userid', compact('userid'));
		if ($user === false) {
			Message::addError('user-not-found', $userid);
			return;
		}
		if ($user['userid'] == 1 || $user['userid'] == User::getId()) {
			Message::addError('cannot-delete-1-self');
			return;
		}
		Database::exec('DELETE FROM user WHERE userid = :userid', compact('userid'));
		Message::addSuccess('user-deleted', $userid);
	}

	protected function doRender()
	{
		Render::addTemplate('header');
		$hasUsers = (Database::queryFirst('SELECT userid FROM user LIMIT 1') !== false);
		$show = Request::get('show', ($hasUsers ? 'list' : 'adduser'), 'string');
		if ($show === 'adduser') {
			// Can add user if: - no user exists yet; - user has explicit permission to add users
			if ($hasUsers) {
				User::assertPermission('user.add');
			}
			Render::addTemplate('page-adduser');
		} elseif ($show === 'edituser') {
			User::assertPermission('user.edit');
			$userid = Request::get('userid', false, 'int');
			if ($userid === false) {
				Message::addError('main.parameter-missing', 'userid');
				Util::redirect('?do=adduser&show=list');
			}
			$user = Database::queryFirst('SELECT userid, login, fullname, phone, email
					FROM user WHERE userid = :userid', compact('userid'));
			if ($user === false) {
				Message::addError('user-not-found', $userid);
			} else {
				// TODO: LDAP -> disallow pw change, maybe other fields too?
				Render::addTemplate('page-edituser', $user);
			}
		} elseif ($show === 'list') {
			User::assertPermission('list.view');
			$page = new Paginate('SELECT userid, login, fullname, phone, email FROM user ORDER BY login', 50);
			$data = ['list' => $page->exec()->fetchAll(PDO::FETCH_ASSOC)];
			foreach ($data['list'] as &$u) {
				// Don't allow deleting user 1 and self
				$u['hide_delete'] = $u['userid'] == 1 || $u['userid'] == User::getId();
			}
			unset($u);
			Permission::addGlobalTags($data['perms'], null, ['user.add', 'user.edit', 'user.remove']);
			$page->render('page-userlist', $data);
		}
	}

}
