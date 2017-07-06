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
			// TODO Validate
			foreach ($machines as $machine) {
				Database::exec("INSERT IGNORE INTO runmode (machineuuid, module, modeid)
					VALUES (:machine, :module, :modeId)", compact('machine', 'module', 'modeId'));
			}
			Database::exec('DELETE FROM runmode
					WHERE module = :module AND modeid = :modeId AND machineuuid NOT IN (:machines)',
				compact('module', 'modeId', 'machines'));
			Util::redirect('?do=runmode&module=' . $module . '&modeid=' . $modeId);
		}
	}

	protected function doRender()
	{
		$moduleId = Request::get('module', false, 'string');
		if ($moduleId !== false) {
			$this->renderModule($moduleId);
			return;
		}
		// TODO
		Message::addInfo('OMGhai2U');
	}

	private function renderModule($moduleId)
	{
		$module = Module::get($moduleId);
		if ($module === false) {
			Message::addError('main.no-such-module', $moduleId);
			Util::redirect('?do=runmode');
		}
		$modeId = Request::get('modeid', false, 'string');
		if ($modeId !== false) {
			$this->renderModuleMode($module, $modeId);
			return;
		}
		// TODO
		Message::addError('main.parameter-missing', 'modeid');
		Util::redirect('?do=runmode');
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
			Message::addError('invalid-modeid', $modeId);
			Util::redirect('?do=runmode');
		}
		Render::addTemplate('machine-selector', [
			'module' => $moduleId,
			'modeid' => $modeId,
			'moduleName' => $module->getDisplayName(),
			'modeName' => $modeName,
			'machines' => json_encode(RunMode::getForMode($module, $modeId, true))
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