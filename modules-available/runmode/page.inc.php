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
		if ($action === 'save-mode') {
			$this->handleSaveMode();
		} elseif ($action === 'delete-machine') {
			$this->handleDeleteMachine();
		}
		if (Request::isPost()) {
			Util::redirect('?do=runmode');
		}
	}

	private function handleSaveMode()
	{
		$machines = array_unique(array_filter(Request::post('machines', [], 'array'), 'is_string'));
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
		// Query existing entries first (for delete - see below)
		$existing = [];
		if ($modConfig->permission !== false) {
			$existing = RunMode::getForMode($module, $modeId, true, true);
		}
		// Before doing anything, check if the user has the proper permission for any location - if not, nothing to do
		if (!$modConfig->userHasPermission(null)) {
			Message::addError('main.no-permission');
			Util::redirect('?do=runmode');
		}
		$active = 0;
		foreach ($machines as $machine) {
			if (isset($existing[$machine])) {
				if (!$modConfig->userHasPermission($existing[$machine]['locationid'])) {
					// Machine was already assigned to this runmode, and user has no permission for its location
					unset($existing[$machine]);
					continue; // So keep it as-is and skip
				}
				// User has permission to add this existing machine, keep going so meta data could be updated
				unset($existing[$machine]);
			} else {
				// Not existing yet in this module/mode combo, but check if it is assigned to some other run mode
				$machineLocation = false;
				$oldMachineMode = RunMode::getRunMode($machine, RunMode::DATA_MACHINE_DATA | RunMode::DATA_DETAILED);
				if ($oldMachineMode !== false) {
					$machineLocation = $oldMachineMode['locationid'];
					$oldModule = RunMode::getModuleConfig($oldMachineMode['module']);
					if ($oldModule !== false) {
						if ($oldMachineMode['module'] !== $module || $oldMachineMode['modeid'] !== $modeId) {
							if (!$oldModule->allowGenericEditor || $oldModule->deleteUrlSnippet !== false) {
								Message::addError('runmode.machine-still-assigned', $machine, $oldMachineMode['module']);
								continue;
							}
						}
						// Permissions for old runmode
						if (!$oldModule->userHasPermission($oldMachineMode['locationid'])) {
							// Show same error message as above - might help the user figure out they have no perm to remove it
							Message::addError('runmode.machine-still-assigned', $machine, $oldMachineMode['module']);
							continue;
						}
					}
				} else {
					// Not existing, no old mode - query machine to get location, so we can do a perm-check for new loc
					$m = Statistics::getMachine($machine, Machine::NO_DATA);
					if ($m !== false) {
						$machineLocation = $m->locationid;
					}
				}
				if ($machineLocation !== false && !$modConfig->userHasPermission($machineLocation)) {
					Message::addError('runmode.machine-no-permission', $machine);
					continue;
				}
			}
			$ret = RunMode::setRunMode($machine, $module, $modeId, null, null);
			if ($ret) {
				$active++;
			} else {
				Message::addError('runmode.machine-not-found', $machine);
			}
		}
		// Make sure inaccessible machines (no permission for location) are preserved on delete
		// Add existing but inaccessible to list
		foreach ($existing as $e) {
			if (!$modConfig->userHasPermission($e['locationid'])) {
				$machines[] = $e['machineuuid'];
			}
		}
		if ($modConfig->deleteUrlSnippet === false) {
			$deleted = Database::exec('DELETE FROM runmode
					WHERE module = :module AND modeid = :modeId AND machineuuid NOT IN (:machines)',
				compact('module', 'modeId', 'machines'));
		} else {
			$deleted = 0;
		}
		Message::addSuccess('runmode.enabled-removed-save', $active, $deleted);
		Util::redirect('?do=runmode&module=' . $module . '&modeid=' . $modeId, true);
	}

	private function handleDeleteMachine()
	{
		$machineuuid = Request::post('machineuuid', false, 'string');
		if ($machineuuid === false) {
			Message::addError('main.parameter-missing', 'machineuuid');
			return;
		}
		$mode = RunMode::getRunMode($machineuuid, RunMode::DATA_MACHINE_DATA | RunMode::DATA_DETAILED);
		if ($mode === false) {
			Message::addError('runmode.machine-not-found', $machineuuid);
			return;
		}
		$modConfig = RunMode::getModuleConfig($mode['module']);
		if ($modConfig === false) {
			Message::addError('module-hasnt-runmode', $mode['moduleName']);
			return;
		}
		if (!$modConfig->allowGenericEditor || $modConfig->deleteUrlSnippet !== false) {
			Message::addError('runmode.cannot-edit-module', $mode['moduleName']);
			return;
		}
		if (!$modConfig->userHasPermission($mode['locationid'])) {
			Message::addError('runmode.machine-no-permission', $machineuuid);
			return;
		}
		if (RunMode::setRunMode($machineuuid, null, null)) {
			Message::addSuccess('machine-removed', $machineuuid);
		} else {
			Message::addWarning('machine-not-runmode', $machineuuid);
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
		$module->activate(1, false);
		$config = RunMode::getModuleConfig($moduleId);
		if ($config === false) {
			Message::addError('module-hasnt-runmode', $moduleId);
			Util::redirect('?do=runmode');
		}
		// Given modeId?
		$modeId = Request::get('modeid', false, 'string');
		if ($modeId !== false) {
			// Show edit page for specific module-mode combo
			$this->renderModuleMode($module, $modeId, $config);
			return;
		}
		// Permissions
		if (!$config->userHasPermission(null) && !User::hasPermission('list-all')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=runmode');
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
			if ($onlyModule === false) {
				// Permissions - not required if rendering specific module, since it's been already done
				if ($rows['config']->userHasPermission(null)) {
					$disabled = '';
				} elseif (User::hasPermission('list-all')) {
					$disabled = 'disabled';
				} else {
					continue;
				} // </Permissions>
			}
			$module = Module::get($moduleId);
			if ($module === false)
				continue;
			$config = RunMode::getModuleConfig($moduleId);
			Render::addTemplate('module-machine-list', array(
				'list' => $rows['list'],
				'modulename' => $module->getDisplayName(),
				'module' => $moduleId,
				'canedit' => $config !== false && $config->allowGenericEditor && $config->deleteUrlSnippet === false,
				'deleteUrl' => $config->deleteUrlSnippet,
				'disabled' => $disabled,
			));
		}
	}

	/**
	 * @param \Module $module
	 * @param string $modeId
	 * @param \RunModeModuleConfig $config
	 */
	private function renderModuleMode($module, $modeId, $config)
	{
		$moduleId = $module->getIdentifier();
		$modeName = RunMode::getModeName($moduleId, $modeId);
		$redirect = Request::get('redirect', '', 'string');
		if (empty($redirect)) {
			$redirect = '?do=runmode';
		}
		if ($modeName === false) {
			Message::addError('invalid-modeid', $moduleId, $modeId);
			Util::redirect($redirect);
		}
		if (!RunMode::getModuleConfig($moduleId)->allowGenericEditor) {
			Message::addError('runmode.cannot-edit-module', $moduleId);
			Util::redirect($redirect);
		}
		// Permissions
		if ($config->userHasPermission(null)) {
			$disabled = '';
		} elseif (User::hasPermission('list-all')) {
			$disabled = 'disabled';
		} else {
			Message::addError('main.no-permission');
			Util::redirect('?do=runmode');
			return;
		}
		$machines = RunMode::getForMode($module, $modeId, true);
		if ($config->permission !== false) {
			$allowed = User::getAllowedLocations($config->permission);
			$machines = array_values(array_filter($machines, function ($item) use ($allowed) {
				return in_array($item['locationid'], $allowed);
			}));
		}
		Render::addTemplate('machine-selector', [
			'module' => $moduleId,
			'modeid' => $modeId,
			'moduleName' => $module->getDisplayName(),
			'modeName' => $modeName,
			'machines' => json_encode($machines),
			'redirect' => $redirect,
			'disabled' => $disabled,
			'add-only' => $config->deleteUrlSnippet !== false,
		]);
	}

	protected function doAjax()
	{
		$action = Request::any('action', false, 'string');
		if ($action !== 'getmachines')
			return;
		$query = Request::get('query', false, 'string');
		if (strlen($query) < 2)
			return;

		User::load();
		$config = RunMode::getModuleConfig(Request::any('module', '', 'string'));
		$returnObject = ['machines' => []];

		if ($config !== false) {
			$params = ['query' => "%$query%"];
			if ($config->permission === false) {
				// Global
				$condition = '1';
			} else {
				$params['locations'] = User::getAllowedLocations($config->permission);
				$condition = 'locationid IN (:locations)';
				if (in_array(0, $params['locations'])) {
					$condition .= ' OR locationid IS NULL';
				}
			}
			if ($config->permission === false || !empty($params['locations'])) {
				$result = Database::simpleQuery("SELECT m.machineuuid, m.macaddr, m.clientip, m.hostname, m.locationid,
					r.module, r.modeid
					FROM machine m
					LEFT JOIN runmode r USING (machineuuid)
					WHERE ($condition) AND (machineuuid LIKE :query
					 OR macaddr  	 LIKE :query
					 OR clientip    LIKE :query
					 OR hostname	 LIKE :query)
					 LIMIT 100", $params);

				$returnObject = [
					'machines' => $result->fetchAll(PDO::FETCH_ASSOC)
				];
			}
		}
		echo json_encode($returnObject);

	}

}