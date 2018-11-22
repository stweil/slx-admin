<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', '', 'string');

		if ($action === 'delete') {
			User::assertPermission('networkshares.save');
			$shareid = Request::post('shareid', false, 'int');
			if ($shareid) {
				$res = Database::exec('DELETE FROM sat.presetnetworkshare WHERE shareid = :shareid', ['shareid' => $shareid]);
				if ($res) Message::addSuccess('networkshare-deleted');
			}
		} else if ($action === 'save') {
			User::assertPermission('networkshares.save');
			$shareid = Request::post('shareid', false, 'int');
			$sharename = Request::post('sharename', false, 'string');
			$path = Request::post('path', false, 'string');
			$target = Request::post('target', null, 'string');
			$username = Request::post('username', null, 'string');
			$password = Request::post('password', null, 'string');
			if ($sharename && $path) {
				if ($shareid) {
					Database::exec('UPDATE sat.presetnetworkshare SET sharename = :sharename, path = :path, target = :target, username = :username, password = :password'
						.' WHERE shareid = :shareid', compact('shareid', 'sharename', 'path', 'target', 'username', 'password'));
				} else {
					Database::exec('INSERT INTO sat.presetnetworkshare (sharename, path, target, username, password, active)'
						.' VALUES (:sharename, :path, :target, :username, :password, 0)', compact('sharename', 'path', 'target', 'username', 'password'));
				}
				Message::addSuccess('networkshare-saved');
			}
		} else if ($action === 'toggleActive') {
			User::assertPermission('networkshares.save');
			$shareid = Request::post('shareid', false, 'int');
			Database::exec('UPDATE sat.presetnetworkshare SET active = !active WHERE shareid = :shareid', compact('shareid'));
		}
		User::assertPermission('networkshares.view');
	}

	public static function doRender()
	{
		$show = Request::get('show', 'list', 'string');
		if ($show === 'list') {
			$res = Database::simpleQuery('SELECT * FROM sat.presetnetworkshare;');
			$rows = array();
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$row['specificUser'] = $row['username'] && $row['password'];
				$rows[] = $row;
			}
			Render::addTemplate('networkshares', ['networkshares' => $rows, 'hasEditPermissions' => User::hasPermission('networkshares.save')]);
		} else if ($show === 'edit') {
			$shareid = Request::get('shareid', 0, 'int');
			$data = Database::queryFirst('SELECT * FROM sat.presetnetworkshare WHERE shareid = :shareid', ['shareid' => $shareid]);
			if ($data['username'] && $data['password']) $data['specificUser'] = 'selected';
			else $data['loggedInUser'] = 'selected';
			Render::addTemplate('networkshares-edit', $data);
		}
	}

}
