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
				// same for permissionmanager
				Database::exec("INSERT INTO `role_x_user` (userid, roleid) VALUES (:id, 1)", ['id' => $id], true);
				EventLog::info('Created first user ' . $login);
			} else {
				EventLog::info(User::getName() . ' created user ' . $login);
			}
			Message::addInfo('adduser-success');
			$this->saveRoles($id);
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
				if (!empty($pass1) && $userid !== User::getId()) {
					$data = [
						'pass' => Crypto::hash6($pass1),
						'userid' => $userid,
					];
					Database::exec('UPDATE user SET passwd = :pass WHERE userid = :userid', $data);
					Message::addSuccess('password-changed');
				}
				$this->saveRoles($userid);
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
		$user = Database::queryFirst('SELECT userid, login
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
		Message::addSuccess('user-deleted', $user['login'], $userid);
	}

	private function saveRoles($userid)
	{
		if (!Module::isAvailable('permissionmanager'))
			return;
		if (!User::hasPermission('.permissionmanager.users.edit-roles'))
			return;
		$roles = Request::post('roles', [], 'array');
		$ret = PermissionDbUpdate::setRolesForUser([$userid], $roles);
		if ($ret > 0) {
			Message::addSuccess('roles-updated');
		}
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
			Render::openTag('form', ['class' => 'form-adduser', 'action' => '?do=adduser', 'method' => 'post']);
			Render::addTemplate('page-adduser');
			Render::addTemplate('js-add-edit');
			if ($hasUsers) {
				$this->showRoles();
			}
			Render::closeTag('form');
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
				$user['password_disabled'] = User::getId() === $userid ? 'disabled' : false;
				// TODO: LDAP -> disallow pw change, maybe other fields too?
				Render::openTag('form', ['class' => 'form-adduser', 'action' => '?do=adduser', 'method' => 'post']);
				Render::addTemplate('page-edituser', $user);
				Render::addTemplate('js-add-edit');
				$this->showRoles($userid);
				Render::closeTag('form');
			}
		} elseif ($show === 'list') {
			User::assertPermission('user.view-list');
			$page = new Paginate('SELECT userid, login, fullname, phone, email FROM user ORDER BY login', 50);
			$data = ['list' => $page->exec()->fetchAll(PDO::FETCH_ASSOC)];
			foreach ($data['list'] as &$u) {
				// Don't allow deleting user 1 and self
				$u['hide_delete'] = $u['userid'] == 1 || $u['userid'] == User::getId();
				if ($u['userid'] == 1) {
					$u['userClass'] = 'slx-bold';
				}
			}
			unset($u);
			Permission::addGlobalTags($data['perms'], null, ['user.add', 'user.edit', 'user.remove']);
			Module::isAvailable('js_stupidtable');
			$page->render('page-userlist', $data);
		}
	}

	private function showRoles($userid = false)
	{
		if (!Module::isAvailable('permissionmanager'))
			return;
		if (!User::hasPermission('.permissionmanager.users.edit-roles'))
			return;
		$data = ['roles' => PermissionUtil::getRoles($userid, false)];
		Render::addTemplate('user-permissions', $data);
	}

}
