<?php

/**
 * Autoloader for the php-ews classes
 */
spl_autoload_register(function ($class) {
	if (strpos($class, 'jamesiarmes') === false)
		return;
	$file = __DIR__ . '/../../exchange-includes/' . str_replace('\\', '/', $class) . '.php';
	if (!file_exists($file))
		return;
	require_once $file;
});

use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Enumeration\DefaultShapeNamesType;
use jamesiarmes\PhpEws\Enumeration\DistinguishedFolderIdNameType;
use jamesiarmes\PhpEws\Enumeration\ItemQueryTraversalType;
use jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use jamesiarmes\PhpEws\Request\FindItemType;
use jamesiarmes\PhpEws\Request\ResolveNamesType;
use jamesiarmes\PhpEws\Type\CalendarViewType;
use jamesiarmes\PhpEws\Type\DistinguishedFolderIdType;
use jamesiarmes\PhpEws\Type\EmailAddressType;
use jamesiarmes\PhpEws\Type\ItemResponseShapeType;

class CourseBackend_Exchange extends CourseBackend
{

	private $username = '';
	private $password = '';
	private $serverAddress;
	private $clientVersion;
	private $timezone = 'W. Europe Standard Time';  // TODO: make this configurable some time
	private $verifyHostname = true;
	private $verifyCert = true;

	/**
	 * @return string return display name of backend
	 */
	public function getDisplayName()
	{
		return "Microsoft Exchange";
	}

	/**
	 * @returns \BackendProperty[] list of properties that need to be set
	 */
	public function getCredentialDefinitions()
	{
		$options = [
			Client::VERSION_2007,
			Client::VERSION_2007_SP1,
			Client::VERSION_2009,
			Client::VERSION_2010,
			Client::VERSION_2010_SP1,
			Client::VERSION_2010_SP2,
			Client::VERSION_2013,
			Client::VERSION_2013_SP1,
			Client::VERSION_2016,
		];
		return [
			new BackendProperty('serverAddress', 'string'),
			new BackendProperty('username', 'string'),
			new BackendProperty('password', 'password'),
			new BackendProperty('clientVersion', $options, Client::VERSION_2016),
			new BackendProperty('verifyCert', 'bool', true),
			new BackendProperty('verifyHostname', 'bool', true)
		];
	}

	/**
	 * @return boolean true if the connection works, false otherwise
	 */
	public function checkConnection()
	{
		$client = $this->getClient();
		$request = new ResolveNamesType();
		$request->UnresolvedEntry = $this->username;
		$request->ReturnFullContactData = false;

		try {
			$response = $client->ResolveNames($request);
		} catch (Exception $e) {
			$this->addError($e->getMessage(), true);
			return false;
		}

		try {
			if ($response->ResponseMessages->ResolveNamesResponseMessage[0]->ResponseCode === "NoError") {
				$mailadress = $response->ResponseMessages->ResolveNamesResponseMessage[0]->ResolutionSet->Resolution[0]->Mailbox->EmailAddress;
				return !empty($mailadress);
			}
		} catch (Exception $e) {
			$this->addError($e->getMessage(), true);
		}
		return false;
	}

	/**
	 * uses json to setCredentials, the json must follow the form given in
	 * getCredentials
	 *
	 * @param array $data assoc array with data required by backend
	 * @returns bool if the credentials were in the correct format
	 */
	public function setCredentialsInternal($data)
	{
		foreach (['username', 'password'] as $field) {
			if (empty($data[$field])) {
				$this->addError('setCredentials: Missing field ' . $field, true);
				return false;
			}
		}

		if (empty($data['serverAddress'])) {
			$this->addError("No url is given", true);
			return false;
		}

		$this->username = $data['username'];
		$this->password = $data['password'];

		$this->serverAddress = $data['serverAddress'];
		$this->clientVersion = $data['clientVersion'];

		$this->verifyHostname = $data['verifyHostname'];
		$this->verifyCert = $data['verifyCert'];

		return true;
	}

	/**
	 * @return int desired caching time of results, in seconds. 0 = no caching
	 */
	public function getCacheTime()
	{
		return 15 * 60;
	}

	/**
	 * @return int age after which timetables are no longer refreshed. should be
	 * greater than CacheTime.
	 */
	public function getRefreshTime()
	{
		return 30 * 60;
	}

	/**
	 * Internal version of fetch, to be overridden by subclasses.
	 *
	 * @param $roomIds array with local ID as key and serverId as value
	 * @return array a recursive array that uses the roomID as key
	 * and has the schedule array as value. A shedule array contains an array in this format:
	 * ["start"=>'JJJJ-MM-DD HH:MM:SS',"end"=>'JJJJ-MM-DD HH:MM:SS',"title"=>string]
	 */
	protected function fetchSchedulesInternal($requestedRoomIds)
	{
		$startDate = new DateTime('today 0:00');
		$endDate = new DateTime('+7 days 0:00');
		$client = $this->getClient();

		$schedules = [];
		foreach ($requestedRoomIds as $roomId) {
			try {
				$items = $this->findEventsForRoom($client, $startDate, $endDate, $roomId);
			} catch (Exception $e) {
				$this->addError("Failed to search for events for room $roomId: '{$e->getMessage()}'", true);
				continue;
			}

			// Iterate over the events that were found, printing some data for each.
			foreach ($items as $item) {
				$start = new DateTime($item->Start);
				$end = new DateTime($item->End);

				$schedules[$roomId][] = array(
					'title' => $item->Subject,
					'start' => $start->format('Y-m-d') . "T" . $start->format('H:i:s'),
					'end' => $end->format('Y-m-d') . "T" . $end->format('H:i:s')
				);
			}
		}
		return $schedules;
	}

	/**
	 * @param \jamesiarmes\PhpEws\Client $client
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @param string $roomAddress
	 * @return \jamesiarmes\PhpEws\Type\CalendarItemType[]
	 */
	public function findEventsForRoom($client, $startDate, $endDate, $roomAddress)
	{
		$request = new FindItemType();
		$request->Traversal = ItemQueryTraversalType::SHALLOW;
		$request->ItemShape = new ItemResponseShapeType();
		$request->ItemShape->BaseShape = DefaultShapeNamesType::ALL_PROPERTIES;

		$request->CalendarView = new CalendarViewType();
		$request->CalendarView->StartDate = $startDate->format('c');
		$request->CalendarView->EndDate = $endDate->format('c');
		$folderId = new DistinguishedFolderIdType();
		$folderId->Id = DistinguishedFolderIdNameType::CALENDAR;
		$folderId->Mailbox = new EmailAddressType();
		$folderId->Mailbox->EmailAddress = $roomAddress;
		$request->ParentFolderIds->DistinguishedFolderId[] = $folderId;
		$response = $client->FindItem($request);
		$response_messages = $response->ResponseMessages->FindItemResponseMessage;

		$items = [];
		foreach ($response_messages as $response_message) {
			// Make sure the request succeeded.
			if ($response_message->ResponseClass !== ResponseClassType::SUCCESS) {
				$code = $response_message->ResponseCode;
				$message = $response_message->MessageText;
				$this->addError("Failed to search for events for room $roomAddress: '$code: $message'", true);
				continue;
			}
			$items = array_merge($items, $response_message->RootFolder->Items->CalendarItem);
		}
		return $items;
	}

	/**
	 * @return \jamesiarmes\PhpEws\Client
	 */
	public function getClient()
	{
		$client = new Client($this->serverAddress, $this->username, $this->password, $this->clientVersion);
		$client->setTimezone($this->timezone);
		$client->setCurlOptions(array(
			CURLOPT_SSL_VERIFYPEER => $this->verifyHostname ? 2 : 0,
			CURLOPT_SSL_VERIFYHOST => $this->verifyCert ? 1 : 0,
		));

		return $client;
	}

}
