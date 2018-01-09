<?php

class Page_RebootControl extends Page
{

	private $action = false;
	private $allowedShutdownLocs = [];
	private $allowedRebootLocs = [];
	private $allowedLocs = [];

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

		$this->allowedShutdownLocs = User::getAllowedLocations("shutdown");
		$this->allowedRebootLocs = User::getAllowedLocations("reboot");
		$this->allowedLocs = array_unique(array_merge($this->allowedShutdownLocs, $this->allowedRebootLocs));

		$this->action = Request::any('action', 'show', 'string');


		if ($this->action === 'startReboot' || $this->action === 'startShutdown') {

			$locationId = Request::post('locationId', false, 'int');
			if ($locationId === false) {
				Message::addError('locations.invalid-location-id', $locationId);
				Util::redirect();
			}

			$shutdown = $this->action === "startShutdown";
			// Check user permission (if user has no permission, the getAllowed-list will be empty and the check will fail)
			if ($shutdown) {
				if (!in_array($locationId, $this->allowedShutdownLocs)) {
					Message::addError('main.no-permission');
					Util::redirect();
				}
			} else {
				if (!in_array($locationId, $this->allowedRebootLocs)) {
					Message::addError('main.no-permission');
					Util::redirect();
				}
			}

			$clients = Request::post('clients');
			if (!is_array($clients) || empty($clients)) {
				Message::addError('no-clients-selected');
				Util::redirect();
			}
			$minutes = Request::post('minutes', 0, 'int');

			$list = RebootQueries::getMachinesByUuid($clients);
			if (count($list) !== count($clients)) {
				// We could go ahead an see which ones were not found in DB but this should not happen anyways unless the
				// user manipulated the request
				Message::addWarning('some-machine-not-found');
			}
			// TODO: Iterate over list and check if a locationid is not in permissions
			// TODO: we could also check if the locationid is equal or a sublocation of the $locationId from above
			// (this would be more of a sanity check though, or does the UI allow selecting machines from different locations)

			$task = RebootControl::execute($list, $shutdown, $minutes, $locationId);

			Util::redirect("?do=rebootcontrol&taskid=".$task["id"]);
		}

	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */

	protected function doRender()
	{
		if ($this->action === 'show') {

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
				$requestedLocation = Request::get('location', 0, 'int');

				// only fill table if user has at least one permission for the location
				if (in_array($requestedLocation, $this->allowedLocs)) {
					$data['data'] = RebootQueries::getMachineTable($requestedLocation);
					$data['allowedToSelect'] = True;
				}

				$data['locations'] = Location::getLocations($requestedLocation, 0, true);
				// Always show public key (it's public, isn't it?)
				$data['pubKey'] = SSHKey::getPublicKey();

				// disable each location user has no permission for
				foreach ($data['locations'] as &$loc) {
					if (!in_array($loc["locationid"], $this->allowedLocs)) {
						$loc["disabled"] = "disabled";
					}
				}

				// Only enable shutdown/reboot-button if user has permission for the location
				if (in_array($requestedLocation, $this->allowedShutdownLocs)) {
					$data['allowedToShutdown'] = True;
				}
				if (in_array($requestedLocation, $this->allowedRebootLocs)) {
					$data['allowedToReboot'] = True;
				}
				$data['allowedToGenerateKey'] = User::hasPermission("newkeypair");

				Render::addTemplate('_page', $data);

			}
		}
	}

	function doAjax()
	{
		$this->action = Request::post('action', false, 'string');
		if ($this->action === 'generateNewKeypair') {
			if (User::hasPermission("newkeypair")) {
				Property::set("rebootcontrol-private-key", false);
				echo SSHKey::getPublicKey();
			} else {
				echo 'No permission.';
			}
		} else {
			echo 'Invalid action.';
		}
	}



}
