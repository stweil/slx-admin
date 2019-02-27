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
				$res = Database::exec('DELETE FROM sat.presetnetworkrule WHERE ruleid = :ruleid', ['ruleid' => $ruleid]);
				if ($res !== false) {
					Message::addSuccess('networkrule-deleted');
				}
			}
		} else if ($action === 'save') {
			User::assertPermission('networkrules.save');
			$ruleid = Request::post('ruleid', 0, 'int');
			$rulename = Request::post('rulename', '', 'string');
			$hosts = Request::post('host', false, 'array');
			$ports = Request::post('port', false, 'array');
			$directions = Request::post('direction', false, 'array');

			$data = [];
			foreach (array_keys($hosts) as $key) {
				if (!isset($hosts[$key]) || !isset($ports[$key]) || !isset($directions[$key]))
					continue;
				if (!in_array($directions[$key], ['IN', 'OUT'], true)) {
					Message::addWarning('networkrule-invalid-direction', $directions[$key]);
					continue;
				}
				settype($ports[$key], 'int');
				if ($ports[$key] < 0 || $ports[$key] > 65535) {
					Message::addWarning('networkrule-invalid-port', $ports[$key]);
					continue;
				}
				if (empty($hosts[$key]) || strpos($hosts[$key], ' ') !== false) { // Rather sloppy...
					Message::addWarning('networkrule-invalid-host', $hosts[$key]);
					continue;
				}
				$data[] = [
					'host' => $hosts[$key],
					'port' => $ports[$key],
					'direction' => $directions[$key],
				];
			}
			if (empty($data)) {
				Message::addError('networkrule-empty-set');
			} else {
				$data = json_encode($data);
				if ($ruleid !== 0) {
					Database::exec('UPDATE sat.presetnetworkrule SET rulename = :rulename, ruledata = :data'
						. ' WHERE ruleid = :ruleid', compact('ruleid', 'rulename', 'data'));
				} else {
					Database::exec('INSERT INTO sat.presetnetworkrule (rulename, ruledata)'
						. ' VALUES (:rulename, :data)', compact('rulename', 'data'));
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
					FROM sat.presetnetworkrule ORDER BY rulename ASC');
			$rows = array();
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$rows[] = $row;
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
						FROM sat.presetnetworkrule WHERE ruleid = :ruleid', ['ruleid' => $ruleid]);
				if ($data === false) {
					Message::addError('networkrule-invalid-ruleid', $ruleid);
					Util::redirect('?do=dozmod&section=networkrules');
				}
				$dec = json_decode($data['ruledata'], true);
				if (!is_array($dec) || !isset($dec[0])) {
					$dec = [[]];
				}
				$data['rules'] = $dec;
				$i = 0;
				foreach ($data['rules'] as &$rule) {
					$rule['index'] = ++$i;
					$rule[$rule['direction'] . '_selected'] = 'selected';
				}
				unset($rule);
			}
			Render::addTemplate('networkrules-edit', $data);
		}
	}

}
