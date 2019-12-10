<?php

class Page_Statistics extends Page
{
	private $query;
	private $show;

	/**
	 * @var bool whether we have a SubPage from the pages/ subdir
	 */
	private $haveSubpage;

	protected function doPreprocess()
	{
		User::load();
		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		if (Request::isGet()) {
			$this->show = Request::any('show', false, 'string');
			if ($this->show === false) {
				if (Request::get('uuid') !== false) {
					$this->show = 'machine';
				} elseif (User::hasPermission('view.summary')) {
					$this->show = 'summary';
				} elseif (User::hasPermission('view.list')) {
					$this->show = 'list';
				} else {
					User::assertPermission('view.summary');
				}
			} else {
				$this->show = preg_replace('/[^a-z0-9_\-]/', '', $this->show);
			}

			if (file_exists('modules/statistics/pages/' . $this->show . '.inc.php')) {
				require_once 'modules/statistics/pages/' . $this->show . '.inc.php';
				$this->haveSubpage = true;
				SubPage::doPreprocess();
			} else {
				Message::addError('main.invalid-action', $this->show);
			}
			return;
		}

		// POST
		$action = Request::post('action');
		if ($action === 'setnotes') {
			$uuid = Request::post('uuid', '', 'string');
			$res = Database::queryFirst('SELECT locationid FROM machine WHERE machineuuid = :uuid',
																array('uuid' => $uuid));
			if ($res === false) {
				Message::addError('unknown-machine', $uuid);
				Util::redirect('?do=statistics');
			}
			User::assertPermission("machine.note.edit", (int)$res['locationid']);
			$text = Request::post('content', null, 'string');
			if (empty($text)) {
				$text = null;
			}
			Database::exec('UPDATE machine SET notes = :text WHERE machineuuid = :uuid', array(
				'uuid' => $uuid,
				'text' => $text,
			));
			Message::addSuccess('notes-saved');
			Util::redirect('?do=statistics&uuid=' . $uuid);
		} elseif ($action === 'delmachines') {
			$this->deleteMachines();
			Util::redirect('?do=statistics', true);
		} elseif ($action === 'rebootmachines') {
			$this->rebootControl(true);
		} elseif ($action === 'shutdownmachines') {
			$this->rebootControl(false);
		} elseif ($action === 'wol') {
			$this->wol();
		} elseif ($action === 'prepare-exec') {
			if (Module::isAvailable('rebootcontrol')) {
				RebootControl::prepareExec();
			}
		}

		// Make sure we don't render any content for POST requests - should be handled above and then
		// redirected properly
		Util::redirect('?do=statistics');
	}

	private function wol()
	{
		if (!Module::isAvailable('rebootcontrol'))
			return;
		$ids = Request::post('uuid', [], 'array');
		$ids = array_values($ids);
		if (empty($ids)) {
			Message::addError('main.parameter-empty', 'uuid');
			return;
		}
		$this->getAllowedMachines(".rebootcontrol.action.wol", $ids, $allowedMachines);
		if (empty($allowedMachines))
			return;
		$taskid = RebootControl::wakeMachines($allowedMachines);
		Util::redirect('?do=rebootcontrol&show=task&what=task&taskid=' . $taskid);
	}

	/**
	 * @param bool $reboot true = reboot, false = shutdown
	 */
	private function rebootControl($reboot)
	{
		if (!Module::isAvailable('rebootcontrol'))
			return;
		$ids = Request::post('uuid', [], 'array');
		$ids = array_values($ids);
		if (empty($ids)) {
			Message::addError('main.parameter-empty', 'uuid');
			return;
		}
		$this->getAllowedMachines(".rebootcontrol.action." . ($reboot ? 'reboot' : 'shutdown'), $ids, $allowedMachines);
		if (empty($allowedMachines))
			return;
		if ($reboot && Request::post('kexec', false)) {
			$action = RebootControl::KEXEC_REBOOT;
		} elseif ($reboot) {
			$action = RebootControl::REBOOT;
		} else {
			$action = RebootControl::SHUTDOWN;
		}
		$task = RebootControl::execute($allowedMachines, $action, 0);
		if (Taskmanager::isTask($task)) {
			Util::redirect("?do=rebootcontrol&show=task&what=task&taskid=" . $task["id"]);
		}
	}

	private function getAllowedMachines($permission, $ids, &$allowedMachines)
	{
		$allowedLocations = User::getAllowedLocations($permission);
		if (empty($allowedLocations)) {
			Message::addError('main.no-permission');
			Util::redirect('?do=statistics');
		}
		$res = Database::simpleQuery('SELECT machineuuid, clientip, macaddr, locationid FROM machine
				WHERE machineuuid IN (:ids)', compact('ids'));
		$ids = array_flip($ids);
		$allowedMachines = [];
		$seenLocations = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			unset($ids[$row['machineuuid']]);
			settype($row['locationid'], 'int');
			if (in_array($row['locationid'], $allowedLocations)) {
				$allowedMachines[] = $row;
			} elseif (!isset($seenLocations[$row['locationid']])) {
				Message::addError('locations.no-permission-location', $row['locationid']);
			}
			$seenLocations[$row['locationid']] = true;
		}
		if (!empty($ids)) {
			Message::addWarning('unknown-machine', implode(', ', array_keys($ids)));
		}
	}

	private function deleteMachines()
	{
		$ids = Request::post('uuid', [], 'array');
		$ids = array_values($ids);
		if (empty($ids)) {
			Message::addError('main.parameter-empty', 'uuid');
			return;
		}
		$allowedLocations = User::getAllowedLocations("machine.delete");
		if (empty($allowedLocations)) {
			Message::addError('main.no-permission');
			Util::redirect('?do=statistics');
		}
		$res = Database::simpleQuery('SELECT machineuuid, locationid FROM machine WHERE machineuuid IN (:ids)', compact('ids'));
		$ids = array_flip($ids);
		$delete = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			unset($ids[$row['machineuuid']]);
			if (in_array($row['locationid'], $allowedLocations)) {
				$delete[] = $row['machineuuid'];
			} else {
				Message::addError('locations.no-permission-location', $row['locationid']);
			}
		}
		if (!empty($delete)) {
			Database::exec('DELETE FROM machine WHERE machineuuid IN (:delete)', compact('delete'));
			Message::addSuccess('deleted-n-machines', count($delete));
		}
		if (!empty($ids)) {
			Message::addWarning('unknown-machine', implode(', ', array_keys($ids)));
		}
	}

	protected function doRender()
	{
		if ($this->haveSubpage) {
			SubPage::doRender();
			return;
		}

		$sortColumn = Request::any('sortColumn');
		$sortDirection = Request::any('sortDirection');

		$filters = StatisticsFilter::parseQuery(StatisticsFilter::getQuery());
		$filterSet = new StatisticsFilterSet($filters);
		$filterSet->setSort($sortColumn, $sortDirection);

		if (!$filterSet->setAllowedLocationsFromPermission('view.' . $this->show)) {
			Message::addError('main.no-permission');
			Util::redirect('?do=main');
		}
		Message::addError('main.value-invalid', 'show', $this->show);
	}

	private function redirectFirst($where, $join, $args)
	{
		// TODO Annoying at times, restore this?
		$res = Database::queryFirst("SELECT machineuuid FROM machine $join WHERE ($where) LIMIT 1", $args);
		if ($res !== false) {
			Util::redirect('?do=statistics&uuid=' . $res['machineuuid']);
		}
	}

	protected function doAjax()
	{
		if (!User::load())
			return;
		if (Request::any('action') === 'bios') {
			require_once 'modules/statistics/pages/machine.inc.php';
			SubPage::ajaxCheckBios();
			return;
		}

		$param = Request::any('lookup', false, 'string');
		if ($param === false) {
			die('No lookup given');
		}
		$add = '';
		if (preg_match('/^([a-f0-9]{4}):([a-f0-9]{4})$/', $param, $out)) {
			$cat = 'DEVICE';
			$host = $out[2] . '.' . $out[1];
			$add = ' (' . $param . ')';
		} elseif (preg_match('/^([a-f0-9]{4})$/', $param, $out)) {
			$cat = 'VENDOR';
			$host = $out[1];
		} elseif (preg_match('/^c\.([a-f0-9]{2})([a-f0-9]{2})$/', $param, $out)) {
			$cat = 'CLASS';
			$host = $out[2] . '.' . $out[1] . '.c';
		} else {
			die('Invalid format requested');
		}
		$cached = Page_Statistics::getPciId($cat, $param);
		if ($cached !== false && $cached['dateline'] > time()) {
			echo $cached['value'], $add;
			exit;
		}
		$res = dns_get_record($host . '.pci.id.ucw.cz', DNS_TXT);
		if (is_array($res)) {
			foreach ($res as $entry) {
				if (isset($entry['txt']) && substr($entry['txt'], 0, 2) === 'i=') {
					$string = substr($entry['txt'], 2);
					Page_Statistics::setPciId($cat, $param, $string);
					echo $string, $add;
					exit;
				}
			}
		}
		if ($cached !== false) {
			echo $cached['value'], $add;
			exit;
		}
		die('Not found');
	}

	public static function getPciId($cat, $id)
	{
		static $cache = [];
		$key = $cat . '-' . $id;
		if (isset($cache[$key]))
			return $cache[$key];
		return $cache[$key] = Database::queryFirst('SELECT value, dateline FROM pciid WHERE category = :cat AND id = :id LIMIT 1',
			array('cat' => $cat, 'id' => $id));
	}

	private static function setPciId($cat, $id, $value)
	{
		Database::exec('INSERT INTO pciid (category, id, value, dateline) VALUES (:cat, :id, :value, :timeout)'
			. ' ON DUPLICATE KEY UPDATE value = VALUES(value), dateline = VALUES(dateline)',
			array(
				'cat' => $cat,
				'id' => $id,
				'value' => $value,
				'timeout' => time() + mt_rand(10, 30) * 86400,
			), true);
	}

}
