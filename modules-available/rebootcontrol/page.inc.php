<?php

class Page_RebootControl extends Page
{

	/**
	 * @var bool whether we have a SubPage from the pages/ subdir
	 */
	private $haveSubpage = false;

	/**
	 * Called before any page rendering happens - early hook to check parameters etc.
	 */
	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main'); // does not return
		}

		if (User::hasPermission('jumphost.*')) {
			Dashboard::addSubmenu('?do=rebootcontrol&show=jumphost', Dictionary::translate('jumphosts', true));
		}
		if (User::hasPermission('subnet.*')) {
			Dashboard::addSubmenu('?do=rebootcontrol&show=subnet', Dictionary::translate('subnets', true));
		}

		$section = Request::any('show', false, 'string');
		if ($section !== false) {
			$section = preg_replace('/[^a-z]/', '', $section);
			if (file_exists('modules/rebootcontrol/pages/' . $section . '.inc.php')) {
				require_once 'modules/rebootcontrol/pages/' . $section . '.inc.php';
				$this->haveSubpage = true;
				SubPage::doPreprocess();
			} else {
				Message::addError('main.invalid-action', $section);
				return;
			}
		} else {
			$action = Request::post('action', 'show', 'string');

			if ($action === 'reboot' || $action === 'shutdown') {
				$this->execRebootShutdown($action);
			} elseif ($action === 'toggle-wol') {
				User::assertPermission('woldiscover');
				$enabled = Request::post('enabled', false);
				Property::set(RebootControl::KEY_AUTOSCAN_DISABLED, !$enabled);
				if ($enabled) {
					Message::addInfo('woldiscover-enabled');
				} else {
					Message::addInfo('woldiscover-disabled');
				}
				$section = 'subnet'; // For redirect below
			}
		}

		if (Request::isPost()) {
			Util::redirect('?do=rebootcontrol' . ($section ? '&show=' . $section : ''));
		} elseif ($section === false) {
			Util::redirect('?do=rebootcontrol&show=task');
		}
	}

	private function execRebootShutdown($action)
	{
		$requestedClients = Request::post('clients', false, 'array');
		if (!is_array($requestedClients) || empty($requestedClients)) {
			Message::addError('no-clients-selected');
			return;
		}

		$actualClients = RebootUtils::getFilteredMachineList($requestedClients, 'action.' . $action);
		if ($actualClients === false)
			return;
		RebootUtils::sortRunningFirst($actualClients);
		if ($action === 'shutdown') {
			$mode = 'SHUTDOWN';
			$minutes = Request::post('s-minutes', 0, 'int');
		} elseif (Request::any('quick', false, 'string') === 'on') {
			$mode = 'KEXEC_REBOOT';
			$minutes = Request::post('r-minutes', 0, 'int');
		} else {
			$mode = 'REBOOT';
			$minutes = Request::post('r-minutes', 0, 'int');
		}
		$task = RebootControl::execute($actualClients, $mode, $minutes);
		if (Taskmanager::isTask($task)) {
			Util::redirect("?do=rebootcontrol&show=task&what=task&taskid=" . $task["id"]);
		}
		return;
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */

	protected function doRender()
	{
		// Always show public key (it's public, isn't it?)
		$data = [
			'pubkey' => SSHKey::getPublicKey(),
			'wol_auto_checked' => Property::get(RebootControl::KEY_AUTOSCAN_DISABLED) ? '' : 'checked',
		];
		Permission::addGlobalTags($data['perms'], null, ['newkeypair', 'woldiscover']);
		Render::addTemplate('header', $data);

		if ($this->haveSubpage) {
			SubPage::doRender();
			return;
		}
	}

	protected function doAjax()
	{
		$action = Request::post('action', false, 'string');
		if ($action === 'generateNewKeypair') {
			User::assertPermission("newkeypair");
			Property::set("rebootcontrol-private-key", false);
			echo SSHKey::getPublicKey();
		} elseif ($action === 'clientstatus') {
			$clients = Request::post('clients');
			if (is_array($clients)) {
				// XXX No permission check here, should we consider this as leaking sensitive information?
				$machines = RebootUtils::getMachinesByUuid(array_values($clients), false, ['machineuuid', 'state']);
				$ret = [];
				foreach ($machines as $machine) {
					switch ($machine['state']) {
					case 'OFFLINE': $val = 'glyphicon-off'; break;
					case 'IDLE': $val = 'glyphicon-ok green'; break;
					case 'OCCUPIED': $val = 'glyphicon-user red'; break;
					case 'STANDBY': $val = 'glyphicon-off green'; break;
					default: $val = 'glyphicon-question-sign'; break;
					}
					$ret[$machine['machineuuid']] = $val;
				}
				Header('Content-Type: application/json; charset=utf-8');
				echo json_encode($ret);
			}
		} else {
			echo 'Invalid action.';
		}
	}

}
