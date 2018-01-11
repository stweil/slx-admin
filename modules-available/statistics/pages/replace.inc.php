<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', false, 'string');
		if ($action === 'replace') {
			self::handleReplace();
		}
		if (Request::isPost()) {
			Util::redirect('?do=statistics&show=replace');
		}
	}

	private static function handleReplace()
	{
		$replace = Request::post('replace', false, 'array');
		if ($replace === false || empty($replace)) {
			Message::addError('main.parameter-empty', 'replace');
			return;
		}
		$list = [];
		foreach ($replace as $p) {
			$split = explode('x', $p);
			if (count($split) !== 2) {
				Message::addError('invalid-replace-format', $p);
				continue;
			}
			$entry = ['old' => $split[0], 'new' => $split[1]];
			$old = Database::queryFirst('SELECT lastseen FROM machine WHERE machineuuid = :old',
				['old' => $entry['old']]);
			if ($old === false) {
				Message::addError('unknown-machine', $entry['old']);
				continue;
			}
			$new = Database::queryFirst('SELECT firstseen FROM machine WHERE machineuuid = :new',
				['new' => $entry['new']]);
			if ($new === false) {
				Message::addError('unknown-machine', $entry['new']);
				continue;
			}
			if ($old['lastseen'] - 86400*7 > $new['firstseen']) {
				Message::addWarning('ignored-both-in-use', $entry['old'], $entry['new']);
				continue;
			}
			$entry['datelimit'] = min($new['firstseen'], $old['lastseen']);
			$list[] = $entry;
		}
		if (empty($list)) {
			Message::addError('main.parameter-empty', 'replace');
			return;
		}

		// First handle module internal tables
		foreach ($list as $entry) {
			Database::exec('UPDATE statistic SET machineuuid = :new WHERE machineuuid = :old AND dateline < :datelimit', $entry);
		}

		// Let other modules do their thing
		$fun = function($file, $list) {
			include $file;
		};
		foreach (Hook::load('statistics-machine-replace') as $hook) {
			$fun($hook->file, $list);
		}

		// Finalize by updating machine table
		foreach ($list as $entry) {
			unset($entry['datelimit']);
			Database::exec('UPDATE machine old, machine new SET
				new.fixedlocationid = old.fixedlocationid,
				new.position = old.position,
				old.position = NULL,
				new.notes = old.notes,
				old.notes = NULL,
				old.lastseen = new.firstseen
				WHERE old.machineuuid = :old AND new.machineuuid = :new', $entry);
		}
		Message::addSuccess('x-machines-replaced', count($list));
	}

	public static function doRender()
	{
		self::listSuggestions();
	}

	private static function listSuggestions()
	{
		if (Request::get('debug', false) !== false) {
			$oldCutoff = time() - 86400 * 180;
			$newCutoff = time() - 86400 * 180;
		} else {
			$oldCutoff = time() - 86400 * 90;
			$newCutoff = time() - 86400 * 30;
		}
		$res = Database::simpleQuery("SELECT
				old.machineuuid AS olduuid, old.locationid AS oldlid, old.hostname AS oldhost,
				old.clientip AS oldip, old.macaddr AS oldmac, old.lastseen AS oldlastseen, old.systemmodel AS oldmodel,
				new.machineuuid AS newuuid, new.locationid AS newlid, new.hostname AS newhost,
				new.clientip AS newip, new.macaddr AS newmac, new.firstseen AS newfirstseen, new.systemmodel AS newmodel
				FROM machine old INNER JOIN machine new ON (old.clientip = new.clientip AND old.lastseen < new.firstseen AND old.lastseen > $oldCutoff AND new.firstseen > $newCutoff)
				ORDER BY oldhost ASC, oldip ASC");
		$list = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['oldlastseen_s'] = Util::prettyTime($row['oldlastseen']);
			$row['newfirstseen_s'] = Util::prettyTime($row['newfirstseen']);
			$list[] = $row;
		}
		$data = array('pairs' => $list);
		Render::addTemplate('page-replace', $data);
		if (empty($list)) {
			Message::addInfo('no-replacement-matches');
		}
	}

}

