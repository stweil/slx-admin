<?php

class SubPage
{

	public static function doPreprocess()
	{
		/* execute actions */
		$action = Request::post('action', false, 'string');

		if ($action === 'save') {
			User::assertPermission("runscripts.save");
			self::saveScript();
		}

		if (Request::isPost()) {
			Util::redirect('?do=dozmod&section=runscripts');
		}
		User::assertPermission('runscripts.view');
	}

	private static function saveScript()
	{
		$id = Request::post('runscriptid', false, 'int');
		$scriptname = Request::post('scriptname', '', 'string');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'runscriptid');
			return;
		}
		// LF vs. CRLF crap -- use LF as soon as there's one non-MS OS selected
		$content = Request::post('content', '', 'string');
		$oslist = Request::post('osid', false, 'array');
		if (is_array($oslist)) {
			$oslist = array_filter($oslist, 'is_numeric');
			$res = Database::queryColumnArray('SELECT o.displayname FROM sat.operatingsystem o
						WHERE o.osid IN (:osid)', ['osid' => $oslist]);
			foreach ($res as $item) {
				if ($item !== 'DOS' && strpos($item, 'Windows') === false) {
					$content = str_replace("\r\n", "\n", $content);
					break;
				}
			}
		}
		$data = [
			'scriptname' => $scriptname,
			'content' => $content,
			'visibility' => Request::post('visibility', 1, 'int'),
			'extension' => preg_replace('/[^a-z0-9_\-~\!\$\=]/i', '', Request::post('extension', '', 'string')),
			'passcreds' => Request::post('passcreds', 0, 'int') !== 0,
			'isglobal' => Request::post('isglobal', 0, 'int') !== 0,
		];
		if ($id === 0) {
			// New entry
			$ret = Database::exec('INSERT INTO sat.presetrunscript
					(scriptname, content, extension, visibility, passcreds, isglobal) VALUES
					(:scriptname, :content, :extension, :visibility, :passcreds, :isglobal)', $data);
			$id = Database::lastInsertId();
		} else {
			// Edit entry
			$data['id'] = $id;
			Database::exec('UPDATE sat.presetrunscript SET
					scriptname = :scriptname, content = :content, extension = :extension, visibility = :visibility,
					passcreds = :passcreds, isglobal = :isglobal
					WHERE runscriptid = :id', $data);
		}
		if (is_array($oslist)) {
			$query = Database::prepare('INSERT INTO sat.presetrunscript_x_operatingsystem
					(runscriptid, osid) VALUES (:id, :osid)');
			foreach ($oslist as $osid) {
				$query->execute(['id' => $id, 'osid' => $osid]);
			}
			$query->closeCursor();
			Database::exec('DELETE FROM sat.presetrunscript_x_operatingsystem
					WHERE runscriptid = :id AND osid NOT IN (:oslist)', ['id' => $id, 'oslist' => $oslist]);
		}
		Message::addSuccess('runscript-saved');
	}

	public static function doRender()
	{
		$show = Request::get('show', 'list', 'string');
		if ($show === 'list') {
			$res = Database::simpleQuery('SELECT runscriptid, scriptname, extension, visibility, passcreds, isglobal
					FROM sat.presetrunscript
					ORDER BY scriptname ASC');
			$rows = [];
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				if ($row['visibility'] == 0) {
					$row['visibility'] = 'eye-close';
				} elseif ($row['visibility'] == 1) {
					$row['visibility'] = 'eye-open';
				} else {
					$row['visibility'] = 'arrow-down';
				}
				$rows[] = $row;
			}
			Render::addTemplate('runscripts-list', ['list' => $rows, 'hasEditPermission' => User::hasPermission('runscripts.save')]);
		} elseif ($show === 'edit') {
			// Edit
			$id = Request::get('runscriptid', false, 'int');
			if ($id === false) {
				Message::addError('main.parameter-missing', 'runscriptid');
				Util::redirect('?do=dozmod&section=runscripts');
			}
			if ($id === 0) {
				$row = [
					'runscriptid' => 0,
					'visibility_1_checked' => 'checked',
					'isglobal_1_checked' => 'checked',
				];
			} else {
				$row = Database::queryFirst('SELECT runscriptid, scriptname, content, extension, visibility, passcreds, isglobal
						FROM sat.presetrunscript
						WHERE runscriptid = :runscriptid', ['runscriptid' => $id]);
				$row['visibility_' . $row['visibility'] . '_selected'] = 'selected';
				$row['passcreds_checked'] = $row['passcreds'] ? 'checked' : '';
				$row['isglobal_' . $row['isglobal'] . '_checked'] = 'checked';
				if ($row === false) {
					Message::addError('runscript-invalid-id', $id);
					Util::redirect('?do=dozmod&section=runscripts');
				}
			}
			// Get OS
			$row['oslist'] = [];
			$res = Database::simpleQuery('SELECT o.osid, o.displayname, pxo.osid AS isvalid FROM sat.operatingsystem o
					LEFT JOIN sat.presetrunscript_x_operatingsystem pxo ON (o.osid = pxo.osid AND pxo.runscriptid = :runscriptid)
					ORDER BY o.displayname ASC', ['runscriptid' => $id]);
			while ($osrow = $res->fetch(PDO::FETCH_ASSOC)) {
				$row['oslist'][] = [
					'osid' => $osrow['osid'],
					'displayname' => $osrow['displayname'],
					'checked' => $osrow['isvalid'] ? 'checked' : '',
				];
			}
			// Output
			Render::addTemplate('runscripts-edit', $row);
		}
	}

	public static function doAjax()
	{

	}

}
