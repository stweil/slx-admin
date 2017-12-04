<?php

class Page_RunMode extends Page
{

	/**
	 * Called before any page rendering happens - early hook to check parameters etc.
	 */
	protected function doPreprocess()
	{
		User::load();
		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=main');
		}
		$action = Request::post('action', false, 'string');
		if ($action !== false) {
			$this->handleAction($action);
			Util::redirect('?do=runmode');
		}
	}

	private function handleAction($action)
	{
		if ($action === 'save-mode') {
			$machines = array_filter(Request::post('machines', [], 'array'), 'is_string');
			$module = Request::post('module', false, 'string');
			$modeId = Request::post('modeid', false, 'string');
			$modConfig = RunMode::getModuleConfig($module);
			if ($modConfig === false) {
				Message::addError('runmode.module-hasnt-runmode', $module);
				return;
			}
			if (!$modConfig->allowGenericEditor) {
				Message::addError('runmode.cannot-edit-module', $module);
				return;
			}
			$test = RunMode::getModeName($module, $modeId);
			if ($test === false) {
				Message::addError('runmode.invalid-modeid', $module, $modeId);
				return;
			}
			$active = 0;
			foreach ($machines as $machine) {
				$ret = RunMode::setRunMode($machine, $module, $modeId, null, null);
				if ($ret) {
					$active++;
				} else {
					Message::addError('runmode.machine-not-found', $machine);
				}
			}
			$deleted = Database::exec('DELETE FROM runmode
					WHERE module = :module AND modeid = :modeId AND machineuuid NOT IN (:machines)',
				compact('module', 'modeId', 'machines'));
			Message::addSuccess('runmode.enabled-removed-save', $active, $deleted);
			Util::redirect('?do=runmode&module=' . $module . '&modeid=' . $modeId, true);
		} elseif ($action === 'delete-machine') {
			$machineuuid = Request::post('machineuuid', false, 'string');
			if ($machineuuid === false) {
				Message::addError('main.parameter-missing', 'machineuuid');
				return;
			}
			$mode = RunMode::getRunMode($machineuuid);
			if ($mode === false) {
				Message::addError('runmode.machine-not-found', $machineuuid);
				return;
			}
			$modConfig = RunMode::getModuleConfig($mode['module']);
			if ($modConfig === false) {
				Message::addError('module-hasnt-runmode', $mode['moduleName']);
				return;
			}
			if (!$modConfig->allowGenericEditor) {
				Message::addError('runmode.cannot-edit-module', $mode['moduleName']);
				return;
			}
			if (RunMode::setRunMode($machineuuid, null, null)) {
				Message::addSuccess('machine-removed', $machineuuid);
			} else {
				Message::addWarning('machine-not-runmode', $machineuuid);
			}
		}
	}

	protected function doRender()
	{
		$moduleId = Request::get('module', false, 'string');
		if ($moduleId !== false) {
			$this->renderModule($moduleId);
			return;
		}
		$this->renderClientList(false);
	}

	private function renderModule($moduleId)
	{
		$module = Module::get($moduleId);
		if ($module === false) {
			Message::addError('main.no-such-module', $moduleId);
			Util::redirect('?do=runmode');
		}
		$module->activate();
		$config = RunMode::getModuleConfig($moduleId);
		if ($config === false) {
			Message::addError('module-hasnt-runmode', $moduleId);
			Util::redirect('?do=runmode');
		}
		if (!$config->allowGenericEditor) {
			Message::addError('runmode.cannot-edit-module', $moduleId);
			return;
		}
		// Given modeId?
		$modeId = Request::get('modeid', false, 'string');
		if ($modeId !== false) {
			// Show edit page for specific module-mode combo
			$this->renderModuleMode($module, $modeId);
			return;
		}
		// Show list of machines with assigned mode for this module
		$this->renderClientList($moduleId);
		Render::setTitle(Page::getModule()->getDisplayName() . ' – ' . $module->getDisplayName());
	}

	private function renderClientList($onlyModule)
	{
		if ($onlyModule === false) {
			$where = '';
		} else {
			$where = ' AND r.module = :moduleId ';
		}
		$res = Database::simpleQuery("SELECT m.machineuuid, m.hostname, m.clientip, r.module, r.modeid, r.isclient"
			. " FROM runmode r"
			. " INNER JOIN machine m ON (m.machineuuid = r.machineuuid $where )"
			. " ORDER BY m.hostname ASC, m.clientip ASC", array('moduleId' => $onlyModule));
		$modules = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($modules[$row['module']])) {
				if (!Module::isAvailable($row['module']))
					continue;
				$modules[$row['module']] = array('config' => RunMode::getModuleConfig($row['module']), 'list' => array());
			}
			if (empty($row['hostname'])) {
				$row['hostname'] = $row['clientip'];
			}
			if ($modules[$row['module']]['config']->getModeName !== false) {
				$row['mode_name'] = call_user_func($modules[$row['module']]['config']->getModeName, $row['modeid']);
			}
			$modules[$row['module']]['list'][] = $row;
		}
		foreach ($modules as $moduleId => $rows) {
			$module = Module::get($moduleId);
			if ($module === false)
				continue;
			$config = RunMode::getModuleConfig($moduleId);
			Render::addTemplate('module-machine-list', array(
				'list' => $rows['list'],
				'modulename' => $module->getDisplayName(),
				'module' => $moduleId,
				'canedit' => $config !== false && $config->allowGenericEditor && $config->deleteUrlSnippet === false,
				'deleteUrl' => $config->deleteUrlSnippet
			));
		}
	}

	/**
	 * @param \Module $module
	 * @param string $modeId
	 */
	private function renderModuleMode($module, $modeId)
	{
		$moduleId = $module->getIdentifier();
		$modeName = RunMode::getModeName($moduleId, $modeId);
		if ($modeName === false) {
			Message::addError('invalid-modeid', $moduleId, $modeId);
			Util::redirect('?do=runmode');
		}
		Render::addTemplate('machine-selector', [
			'module' => $moduleId,
			'modeid' => $modeId,
			'moduleName' => $module->getDisplayName(),
			'modeName' => $modeName,
			'machines' => json_encode(RunMode::getForMode($module, $modeId, true)),
			'redirect' => Request::get('redirect', '', 'string'),
		]);
	}

	protected function doAjax()
	{
		$action = Request::any('action', false, 'string');

		if ($action === 'getmachines') {
			$query = Request::get('query', false, 'string');

			$result = Database::simpleQuery('SELECT m.machineuuid, m.macaddr, m.clientip, m.hostname, m.locationid, '
				. 'r.module, r.modeid '
				. 'FROM machine m '
				. 'LEFT JOIN runmode r USING (machineuuid) '
				. 'WHERE machineuuid LIKE :query '
				. ' OR macaddr  	 LIKE :query '
				. ' OR clientip    LIKE :query '
				. ' OR hostname	 LIKE :query '
				. ' LIMIT 100', ['query' => "%$query%"]);

			$returnObject = [
				'machines' => $result->fetchAll(PDO::FETCH_ASSOC)
			];

			echo json_encode($returnObject);
		}
	}

}