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
			}
		}

		if (Request::isPost()) {
			Util::redirect('?do=rebootcontrol' . ($section ? '&show=' . $section : ''));
		}
	}

	private function execRebootShutdown($action)
	{
		$requestedClients = Request::post('clients', false, 'array');
		if (!is_array($requestedClients) || empty($requestedClients)) {
			Message::addError('no-clients-selected');
			return;
		}

		$actualClients = RebootQueries::getMachinesByUuid($requestedClients);
		if (count($actualClients) !== count($requestedClients)) {
			// We could go ahead an see which ones were not found in DB but this should not happen anyways unless the
			// user manipulated the request
			Message::addWarning('some-machine-not-found');
		}
		// Filter ones with no permission
		foreach (array_keys($actualClients) as $idx) {
			if (!User::hasPermission('action.' . $action, $actualClients[$idx]['locationid'])) {
				Message::addWarning('locations.no-permission-location', $actualClients[$idx]['locationid']);
				unset($actualClients[$idx]);
			} else {
				$locationId = $actualClients[$idx]['locationid'];
			}
		}
		// See if anything is left
		if (!is_array($actualClients) || empty($actualClients)) {
			Message::addError('no-clients-selected');
			return;
		}
		usort($actualClients, function($a, $b) {
			$a = ($a['state'] === 'IDLE' || $a['state'] === 'OCCUPIED');
			$b = ($b['state'] === 'IDLE' || $b['state'] === 'OCCUPIED');
			if ($a === $b)
				return 0;
			return $a ? -1 : 1;
		});
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
		$task = RebootControl::execute($actualClients, $mode, $minutes, $locationId);
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
		$data = ['pubkey' => SSHKey::getPublicKey()];
		Permission::addGlobalTags($data['perms'], null, ['newkeypair']);
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
		} else {
			echo 'Invalid action.';
		}
	}

}
