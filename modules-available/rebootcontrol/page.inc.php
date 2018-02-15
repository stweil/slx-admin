<?php

class Page_RebootControl extends Page
{

	private $action = false;

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

		$this->action = Request::any('action', 'show', 'string');


		if ($this->action === 'reboot' || $this->action === 'shutdown') {

			$requestedClients = Request::post('clients', false, 'array');
			if (!is_array($requestedClients) || empty($requestedClients)) {
				Message::addError('no-clients-selected');
				Util::redirect();
			}
			$minutes = Request::post('minutes', 0, 'int');

			$actualClients = RebootQueries::getMachinesByUuid($requestedClients);
			if (count($actualClients) !== count($requestedClients)) {
				// We could go ahead an see which ones were not found in DB but this should not happen anyways unless the
				// user manipulated the request
				Message::addWarning('some-machine-not-found');
			}
			// Filter ones with no permission
			foreach (array_keys($actualClients) as $idx) {
				if (!User::hasPermission('action.' . $this->action, $actualClients[$idx]['locationid'])) {
					Message::addWarning('main.location-no-permission', $actualClients[$idx]['locationid']);
					unset($actualClients[$idx]);
				} else {
					$locationId = $actualClients[$idx]['locationid'];
				}
			}
			// See if anything is left
			if (!is_array($actualClients) || empty($actualClients)) {
				Message::addError('no-clients-selected');
				Util::redirect();
			}

			$task = RebootControl::execute($actualClients, $this->action === 'shutdown', $minutes, $locationId);
			Util::redirect("?do=rebootcontrol&taskid=".$task["id"]);
		}

	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */

	protected function doRender()
	{
		if ($this->action === 'show') {

			$data = [];
			$taskId = Request::get("taskid");

			if ($taskId && Taskmanager::isTask($taskId)) {
				$task = Taskmanager::status($taskId);
				$data['taskId'] = $taskId;
				$data['locationId'] = $task['data']['locationId'];
				$data['locationName'] = Location::getName($task['data']['locationId']);
				$data['clients'] = $task['data']['clients'];
				Render::addTemplate('status', $data);
			} else {

				//location you want to see, default are "not  assigned" clients
				$requestedLocation = Request::get('location', false, 'int');
				$allowedLocs = User::getAllowedLocations("action.*");

				if ($requestedLocation === false) {
					if (in_array(0, $allowedLocs)) {
						$requestedLocation = 0;
					} elseif (!empty($allowedLocs)) {
						$requestedLocation = reset($allowedLocs);
					}
				}

				$data['locations'] = Location::getLocations($requestedLocation, 0, true);

				// disable each location user has no permission for
				foreach ($data['locations'] as &$loc) {
					if (!in_array($loc["locationid"], $allowedLocs)) {
						$loc["disabled"] = "disabled";
					}
				}
				// Always show public key (it's public, isn't it?)
				$data['pubKey'] = SSHKey::getPublicKey();

				// Only enable shutdown/reboot-button if user has permission for the location
				Permission::addGlobalTags($data['perms'], $requestedLocation, ['newkeypair', 'action.shutdown', 'action.reboot']);

				Render::addTemplate('header', $data);

				// only fill table if user has at least one permission for the location
				if ($requestedLocation === false) {
					Message::addError('main.no-permission');
				} else {
					$data['data'] = RebootQueries::getMachineTable($requestedLocation);
					Render::addTemplate('_page', $data);
				}

			}
		}
	}

	function doAjax()
	{
		$this->action = Request::post('action', false, 'string');
		if ($this->action === 'generateNewKeypair') {
			User::assertPermission("newkeypair");
			Property::set("rebootcontrol-private-key", false);
			echo SSHKey::getPublicKey();
		} else {
			echo 'Invalid action.';
		}
	}



}
