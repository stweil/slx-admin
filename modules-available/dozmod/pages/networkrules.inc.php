<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', '', 'string');

		if ($action === 'delete') {
			User::assertPermission('networkrules.save');
			$ruleid = Request::post('ruleid', false, 'int');
			if ($ruleid !== false) {
				$res = Database::exec('DELETE FROM sat.presetnetworkrules WHERE ruleid = :ruleid', ['ruleid' => $ruleid]);
				if ($res !== false) {
					Message::addSuccess('networkrule-deleted');
				}
			}
		} else if ($action === 'save') {
			User::assertPermission('networkrules.save');
			$ruleid = Request::post('ruleid', 0, 'int');
			$rulename = Request::post('rulename', '', 'string');
			$host = Request::post('host', '', 'string');
			$port = Request::post('port', '', 'string');
			$direction = Request::post('direction', '', 'string');

			if (!in_array($direction, ['IN', 'OUT'], true)) {
				Message::addError('networkrule-invalid-direction', $direction);
			} elseif (empty($host)) {
				Message::addError('networkrule-missing-host');
			} elseif (empty($port)) {
				Message::addError('networkrule-missing-port');
			} else {
				$data = json_encode([
					'host' => $host,
					'port' => $port,
					'direction' => $direction
				]);
				if ($ruleid !== 0) {
					Database::exec('UPDATE sat.presetnetworkrules SET rulename = :rulename, ruledata = :data'
						.' WHERE ruleid = :ruleid', compact('ruleid', 'rulename', 'data'));
				} else {
					Database::exec('INSERT INTO sat.presetnetworkrules (rulename, ruledata)'
						.' VALUES (:rulename, :data)', compact('rulename', 'data'));
				}
				Message::addSuccess('networkrule-saved');
			}
		}
		if (Request::isPost()) {
			Util::redirect('?do=dozmod&section=networkrules');
		}
		User::assertPermission('networkrules.view');
	}

	public static function doRender()
	{
		$show = Request::get('show', 'list', 'string');
		if ($show === 'list') {
			$res = Database::simpleQuery('SELECT ruleid, rulename, ruledata
					FROM sat.presetnetworkrules ORDER BY rulename ASC');
			$rows = array();
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$dec = json_decode($row['ruledata'], true);
				if (!is_array($dec)) {
					$dec = [];
				}
				$rows[] = $row + $dec;
			}
			Render::addTemplate('networkrules', [
				'networkrules' => $rows,
				'hasEditPermissions' => User::hasPermission('networkrules.save')
			]);
		} else if ($show === 'edit') {
			$ruleid = Request::get('ruleid', 0, 'int');
			if ($ruleid === 0) {
				$data = [];
			} else {
				$data = Database::queryFirst('SELECT ruleid, rulename, ruledata
						FROM sat.presetnetworkrules WHERE ruleid = :ruleid', ['ruleid' => $ruleid]);
				if ($data === false) {
					Message::addError('networkrule-invalid-ruleid', $ruleid);
					Util::redirect('?do=dozmod&section=networkrules');
				}
				$dec = json_decode($data['ruledata'], true);
				if (is_array($dec)) {
					$data += $dec;
				}
				if ($data['direction'] === 'IN') {
					$data['inSelected'] = 'selected';
				} else {
					$data['outSelected'] = 'selected';
				}
			}
			Render::addTemplate('networkrules-edit', $data);
		}
	}

}
