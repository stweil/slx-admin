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

			$actualClients = RebootQueries::getMachinesByUuid($requestedClients);
			if (count($actualClients) !== count($requestedClients)) {
				// We could go ahead an see which ones were not found in DB but this should not happen anyways unless the
				// user manipulated the request
				Message::addWarning('some-machine-not-found');
			}
			// Filter ones with no permission
			foreach (array_keys($actualClients) as $idx) {
				if (!User::hasPermission('action.' . $this->action, $actualClients[$idx]['locationid'])) {
					Message::addWarning('locations.no-permission-location', $actualClients[$idx]['locationid']);
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
			usort($actualClients, function($a, $b) {
				$a = ($a['state'] === 'IDLE' || $a['state'] === 'OCCUPIED');
				$b = ($b['state'] === 'IDLE' || $b['state'] === 'OCCUPIED');
				if ($a === $b)
					return 0;
				return $a ? -1 : 1;
			});
			if ($this->action === 'shutdown') {
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
				Util::redirect("?do=rebootcontrol&taskid=" . $task["id"]);
			} else {
				Util::redirect("?do=rebootcontrol");
			}
		}

	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */

	protected function doRender()
	{
		if ($this->action === 'show') {

			$data = [];
			$task = Request::get("taskid", false, 'string');
			if ($task !== false) {
				$task = Taskmanager::status($task);
			}

			if (Taskmanager::isTask($task)) {

				$data['taskId'] = $task['id'];
				$data['locationId'] = $task['data']['locationId'];
				$data['locationName'] = Location::getName($task['data']['locationId']);
				$uuids = array_map(function($entry) {
					return $entry['machineuuid'];
				}, $task['data']['clients']);
				$data['clients'] = RebootQueries::getMachinesByUuid($uuids);
				Render::addTemplate('status', $data);

			} else {

				//location you want to see, default are "not  assigned" clients
				$requestedLocation = Request::get('location', false, 'int');
				$allowedLocs = User::getAllowedLocations("action.*");
				if (empty($allowedLocs)) {
					User::assertPermission('action.*');
				}

				if ($requestedLocation === false) {
					if (in_array(0, $allowedLocs)) {
						$requestedLocation = 0;
					} else {
						$requestedLocation = reset($allowedLocs);
					}
				}

				$data['locations'] = Location::getLocations($requestedLocation, 0, true);

				// disable each location user has no permission for
				foreach ($data['locations'] as &$loc) {
					if (!in_array($loc["locationid"], $allowedLocs)) {
						$loc["disabled"] = "disabled";
					} elseif ($loc["locationid"] == $requestedLocation) {
						$data['location'] = $loc['locationname'];
					}
				}
				// Always show public key (it's public, isn't it?)
				$data['pubKey'] = SSHKey::getPublicKey();

				// Only enable shutdown/reboot-button if user has permission for the location
				Permission::addGlobalTags($data['perms'], $requestedLocation, ['newkeypair', 'action.shutdown', 'action.reboot']);

				Render::addTemplate('header', $data);

				// only fill table if user has at least one permission for the location
				if (!in_array($requestedLocation, $allowedLocs)) {
					Message::addError('locations.no-permission-location', $requestedLocation);
				} else {
					$data['data'] = RebootQueries::getMachineTable($requestedLocation);
					Render::addTemplate('_page', $data);
				}

				// Append list of active reboot/shutdown tasks
				$active = RebootControl::getActiveTasks($allowedLocs);
				if (!empty($active)) {
					foreach ($active as &$entry) {
						$entry['locationName'] = Location::getName($entry['locationId']);
					}
					unset($entry);
					Render::addTemplate('task-list', ['list' => $active]);
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
