<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', '', 'string');

		if ($action === 'delete') {
			User::assertPermission('networkshares.save');
			$shareid = Request::post('shareid', false, 'int');
			if ($shareid !== false) {
				$res = Database::exec('DELETE FROM sat.presetnetworkshare WHERE shareid = :shareid', ['shareid' => $shareid]);
				if ($res !== false) {
					Message::addSuccess('networkshare-deleted');
				}
			}
		} else if ($action === 'save') {
			User::assertPermission('networkshares.save');
			$shareid = Request::post('shareid', 0, 'int');
			$sharename = Request::post('sharename', '', 'string');
			$path = Request::post('path', false, 'string');
			$target = Request::post('target', '', 'string');
			$authType = Request::post('auth', '', 'string');
			$username = Request::post('username', '', 'string');
			$password = Request::post('password', '', 'string');
			if (!in_array($authType, ['LOGIN_USER', 'OTHER_USER'], true)) {
				Message::addError('networkshare-invalid-auth-type', $authType);
			} elseif (empty($path)) {
				Message::addError('networkshare-missing-path');
			} else {
				$data = json_encode([
					'auth' => $authType,
					'path' => $path,
					'displayname' => $sharename,
					'mountpoint' => $target,
					'username' => $username,
					'password' => $password,
				]);
				if ($shareid !== 0) {
					Database::exec('UPDATE sat.presetnetworkshare SET sharename = :sharename, sharedata = :data'
						.' WHERE shareid = :shareid', compact('shareid', 'sharename', 'data'));
				} else {
					Database::exec('INSERT INTO sat.presetnetworkshare (sharename, sharedata, active)'
						.' VALUES (:sharename, :data, 1)', compact('sharename', 'data'));
				}
				Message::addSuccess('networkshare-saved');
			}
		} else if ($action === 'activate' || $action === 'deactivate') {
			User::assertPermission('networkshares.save');
			$shareid = Request::post('shareid', false, 'int');
			$active = ($action === 'activate' ? 1 : 0);
			Database::exec('UPDATE sat.presetnetworkshare SET active = :active WHERE shareid = :shareid', compact('active', 'shareid'));
		}
		if (Request::isPost()) {
			Util::redirect('?do=dozmod&section=networkshares');
		}
		User::assertPermission('networkshares.view');
	}

	public static function doRender()
	{
		$show = Request::get('show', 'list', 'string');
		if ($show === 'list') {
			$res = Database::simpleQuery('SELECT shareid, sharename, sharedata, active
					FROM sat.presetnetworkshare ORDER BY sharename ASC');
			$rows = array();
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$dec = json_decode($row['sharedata'], true);
				if (!is_array($dec)) {
					$dec = [];
				}
				if ($dec['auth'] === 'LOGIN_USER') {
					$row['loginAsUser'] = true;
				}
				$rows[] = $row + $dec;
			}
			Render::addTemplate('networkshares', [
				'networkshares' => $rows,
				'hasEditPermissions' => User::hasPermission('networkshares.save')
			]);
		} else if ($show === 'edit') {
			$shareid = Request::get('shareid', 0, 'int');
			if ($shareid === 0) {
				$data = [];
			} else {
				$data = Database::queryFirst('SELECT shareid, sharename, sharedata
						FROM sat.presetnetworkshare WHERE shareid = :shareid', ['shareid' => $shareid]);
				if ($data === false) {
					Message::addError('networkshare-invalid-shareid', $shareid);
					Util::redirect('?do=dozmod&section=networkshares');
				}
				$dec = json_decode($data['sharedata'], true);
				if (is_array($dec)) {
					$data += $dec;
				}
				if ($data['auth'] === 'LOGIN_USER') {
					$data['loggedInUser_selected'] = 'selected';
				} else {
					$data['specificUser_selected'] = 'selected';
				}
			}
			Render::addTemplate('networkshares-edit', $data);
		}
	}

}
