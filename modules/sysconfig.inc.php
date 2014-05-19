<?php

class Page_SysConfig extends Page
{

	protected function doPreprocess()
	{
		User::load();

		// Read request vars
		$action = Request::any('action', 'list');
		$step = Request::any('step', 0);

		// Action: "addmodule" (upload new module)
		if ($action === 'addmodule') {
			if ($step === 0) $step = 'AddModule_Start';
			require_once 'modules/sysconfig/addmodule.inc.php';
			foreach (glob('modules/sysconfig/addmodule_*.inc.php') as $file) {
				require_once $file;
			}
			AddModule_Base::setStep($step);
			AddModule_Base::preprocess();
		}

		// Action "activate" (set sysconfig as active)
		if ($action === 'activate') {
			if (!User::hasPermission('superadmin')) {
				Message::addError('no-permission');
				Util::redirect('?do=SysConfig');
			}
			if (!isset($_REQUEST['file'])) {
				Message::addError('missing-file');
				Util::redirect('?do=SysConfig');
			}
			$file = preg_replace('/[^a-z0-9\-_\.]/', '', $_REQUEST['file']);
			$path = CONFIG_TGZ_LIST_DIR . '/' . $file;
			if (!file_exists($path)) {
				Message::addError('invalid-file', $file);
				Util::redirect('?do=SysConfig');
			}
			mkdir(CONFIG_HTTP_DIR . '/default', 0755, true);
			$linkname = CONFIG_HTTP_DIR . '/default/config.tgz';
			@unlink($linkname);
			if (file_exists($linkname)) Util::traceError('Could not delete old config.tgz link!');
			if (!symlink($path, $linkname)) Util::traceError("Could not symlink to $path at $linkname!");
			Message::addSuccess('config-activated');
			Util::redirect('?do=SysConfig');
		}
	}

	/**
	 * Render module; called by main script when this module page should render
	 * its content.
	 */
	protected function doRender()
	{
		$action = Request::any('action', 'list');
		switch ($action) {
		case 'addmodule':
			AddModule_Base::render();
			break;
		case 'list':
			$this->rr_list_configs();
			break;
		default:
			Message::addError('invalid-action', $action);
			break;
		}
	}

	private function rr_list_configs()
	{
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			return;
		}
		$res = Database::simpleQuery("SELECT title FROM configtgz_module ORDER BY title ASC");
		$modules = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$modules[] = array(
				'module' => $row['title']
			);
		}
		Render::addTemplate('page-sysconfig-main', array('modules' => $modules, 'token' => Session::get('token')));
	}

}
