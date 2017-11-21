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


		if ($this->action === 'startReboot' || $this->action === 'startShutdown') {
			$clients = Request::post('clients');
			if (!is_array($clients) || empty($clients)) {
				Message::addError('no-clients-selected');
				Util::redirect();
			}
			$locationId = Request::post('locationId', false, 'int');
			if ($locationId === false) {
				Message::addError('locations.invalid-location-id', $locationId);
				Util::redirect();
			}
			$shutdown = $this->action === "startShutdown";
			$minutes = Request::post('minutes', 0, 'int');
			$privKey = SSHKey::getPrivateKey();

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

				$data['data'] = RebootQueries::getMachineTable($requestedLocation);
				$data['locations'] = Location::getLocations($requestedLocation, 0, true);

				$data['pubKey'] = SSHKey::getPublicKey();

				Render::addTemplate('_page', $data);
			}
		}
	}

	function doAjax()
	{
		$this->action = Request::post('action', false, 'string');
		if ($this->action === 'generateNewKeypair') {
			Property::set("rebootcontrol-private-key", false);
			echo SSHKey::getPublicKey();
		} else {
			echo 'Invalid action.';
		}
	}



}
