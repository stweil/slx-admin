<?php

class CourseBackend_HisInOne extends CourseBackend
{
	private $username = '';
	private $password = '';
	private $open = true;
	private $location;
	private $verifyHostname = true;
	private $verifyCert = true;


	public function setCredentialsInternal($data)
	{
		if (!$data['open']) {
			// If not using OpenCourseService, require credentials
			foreach (['username', 'password'] as $field) {
				if (empty($data[$field])) {
					$this->error = 'setCredentials: Missing field ' . $field;
					return false;
				}
			}
		}
		if (empty($data['baseUrl'])) {
			$this->error = "No url is given";
			return false;
		}

		$this->error = false;
		$this->username = $data['username'] . "\t" . $data['role'];
		$this->password = $data['password'];
		$this->open = $data['open'] !== 'CourseService';
		$url = preg_replace('#(/+qisserver(/+services\d+(/+OpenCourseService)?)?)?\W*$#i', '', $data['baseUrl']);
		if ($this->open) {
			$this->location = $url . "/qisserver/services2/OpenCourseService";
		} else {
			$this->location = $url . "/qisserver/services2/CourseService";
		}
		$this->verifyHostname = $data['verifyHostname'];
		$this->verifyCert = $data['verifyCert'];

		return true;
	}

	public function getCredentialDefinitions()
	{
		return [
			new BackendProperty('baseUrl', 'string'),
			new BackendProperty('username', 'string'),
			new BackendProperty('role', 'string'),
			new BackendProperty('password', 'password'),
			new BackendProperty('open', ['OpenCourseService', 'CourseService'], 'OpenCourseService'),
			new BackendProperty('verifyCert', 'bool', true),
			new BackendProperty('verifyHostname', 'bool', true)
		];
	}

	public function checkConnection()
	{
		if (empty($this->location)) {
			$this->error = "Credentials are not set";
		} else {
			$this->findUnit(123456789, true);
		}
		return $this->error === false;
	}

	/**
	 * @param int $roomId his in one room id to get
	 * @param bool $connectionCheckOnly true will only check if no soapError is returned, return value will be empty
	 * @return array|bool if successful an array with the event ids that take place in the room
	 */
	public function findUnit($roomId, $connectionCheckOnly = false)
	{
		$termYear = date('Y');
		$termType1 = date('n');
		if ($termType1 > 3 && $termType1 < 10) {
			$termType = 2;
		} elseif ($termType1 > 10) {
			$termType = 1;
			$termYear = $termYear + 1;
		} else {
			$termType = 1;
		}
		$doc = new DOMDocument('1.0', 'utf-8');
		$doc->formatOutput = true;
		$envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'SOAP-ENV:Envelope');
		$doc->appendChild($envelope);
		if ($this->open) {
			$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ns1', 'http://www.his.de/ws/OpenCourseService');
		} else {
			$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ns1', 'http://www.his.de/ws/CourseService');
			$header = $this->getHeader($doc);
			$envelope->appendChild($header);
		}
		//Body of the request
		$body = $doc->createElement('SOAP-ENV:Body');
		$envelope->appendChild($body);
		$findUnit = $doc->createElement('ns1:findUnit');
		$body->appendChild($findUnit);
		$findUnit->appendChild($doc->createElement('termYear', $termYear));
		if ($termType1 != 3 && $termType1 != 10) {
			$findUnit->appendChild($doc->createElement('termTypeValueId', $termType));
		}
		$findUnit->appendChild($doc->createElement('ns1:roomId', $roomId));

		$soap_request = $doc->saveXML();
		$response1 = $this->postToServer($soap_request, "findUnit");
		if ($this->error !== false) {
			return false;
		}
		$response2 = $this->xmlStringToArray($response1);
		if (!is_array($response2)) {
			if ($this->error === false) {
				$this->error = 'Cannot convert XML response to array';
			}
			return false;
		}
		if (!isset($response2['soapenvBody'])) {
			$this->error = 'findUnit(' . $roomId . '): Backend reply is missing element soapenvBody';
			return false;
		}
		if (isset($response2['soapenvBody']['soapenvFault'])) {
			$this->error = $response2['soapenvBody']['soapenvFault']['faultcode'] . " " . $response2['soapenvBody']['soapenvFault']['faultstring'];
			return false;
		}
		// We only need to check if the connection is working (URL ok, credentials ok, ..) so bail out early
		if ($connectionCheckOnly) {
			return array();
		}
		if ($this->open) {
			$path = '/soapenvBody/hisfindUnitResponse/hisunits/hisunit/hisid';
		} else {
			$path = '/soapenvBody/hisfindUnitResponse/hisunitIds/hisid';
		}
		$id = $this->getArrayPath($response2, $path);
		if ($id === false) {
			$this->error = 'Cannot find ' . $path;
		}
		return $id;
	}

	/**
	 * @param $doc DOMDocument
	 * @return DOMElement
	 */
	private function getHeader($doc)
	{
		$header = $doc->createElement('SOAP-ENV:Header');
		$security = $doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'ns2:Security');
		$mustunderstand = $doc->createAttribute('SOAP-ENV:mustUnderstand');
		$mustunderstand->value = 1;
		$security->appendChild($mustunderstand);
		$header->appendChild($security);
		$token = $doc->createElement('ns2:UsernameToken');
		$security->appendChild($token);
		$user = $doc->createElement('ns2:Username', $this->username);
		$token->appendChild($user);
		$pass = $doc->createElement('ns2:Password', $this->password);
		$type = $doc->createAttribute('Type');
		$type->value = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';
		$pass->appendChild($type);
		$token->appendChild($pass);
		return $header;
	}

	/**
	 * @param $request string with xml SOAP request
	 * @param $action string with the name of the SOAP action
	 * @return bool|string if successful the answer xml from the SOAP server
	 */
	private function postToServer($request, $action)
	{
		$header = array(
			"Content-type: text/xml;charset=\"utf-8\"",
			"SOAPAction: \"" . $action . "\"",
			"Content-length: " . strlen($request),
		);

		$soap_do = curl_init();

		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYHOST => $this->verifyHostname ? 2 : 0,
			CURLOPT_SSL_VERIFYPEER => $this->verifyCert ? 1 : 0,
			CURLOPT_URL => $this->location,
			CURLOPT_POSTFIELDS => $request,
			CURLOPT_HTTPHEADER => $header,
		);

		curl_setopt_array($soap_do, $options);

		$output = curl_exec($soap_do);

		if ($output === false) {
			$this->error = 'Curl error: ' . curl_error($soap_do);
		} else {
			$this->error = false;
			///Operation completed successfully
		}
		curl_close($soap_do);
		return $output;
	}

	public function getCacheTime()
	{
		return 30 * 60;
	}


	public function getRefreshTime()
	{
		return 60 * 60;
	}


	public function getDisplayName()
	{
		return "HisInOne";
	}

	public function fetchSchedulesInternal($requestedRoomIds)
	{
		if (empty($requestedRoomIds)) {
			return array();
		}
		$tTables = [];
		//get all eventIDs in a given room
		$eventIds = [];
		foreach ($requestedRoomIds as $roomId) {
			$roomEventIds = $this->findUnit($roomId);
			if ($roomEventIds === false) {
				error_log($this->error);
				$this->error = false;
				// TODO: Error gets swallowed
				continue;
			}
			$tTables[$roomId] = [];
			$eventIds = array_merge($eventIds, $roomEventIds);
		}
		$eventIds = array_unique($eventIds);
		if (empty($eventIds)) {
			return $tTables;
		}
		$eventDetails = [];
		//get all information on each event
		foreach ($eventIds as $eventId) {
			$event = $this->readUnit(intval($eventId));
			if ($event === false) {
				error_log($this->error);
				$this->error = false;
				// TODO: Error gets swallowed
				continue;
			}
			$eventDetails = array_merge($eventDetails, $event);
		}
		$currentWeek = $this->getCurrentWeekDates();
		foreach ($eventDetails as $event) {
			foreach (array('/hisdefaulttext',
							'/hisshorttext',
							'/hisshortcomment',
							'/hisplanelements/hisplanelement/hisdefaulttext') as $path) {
				$name = $this->getArrayPath($event, $path);
				if (!empty($name) && !empty($name[0]))
					break;
				$name = false;
			}
			if ($name === false) {
				$name = ['???'];
			}
			$unitPlannedDates = $this->getArrayPath($event,
				'/hisplanelements/hisplanelement/hisplannedDates/hisplannedDate/hisindividualDates/hisindividualDate');
			if ($unitPlannedDates === false) {
				$this->error = 'Cannot find ./hisplanelements/hisplanelement/hisplannedDates/hisplannedDate/hisindividualDates/hisindividualDate';
				return false;
			}
			foreach ($unitPlannedDates as $plannedDate) {
				$eventRoomId = $this->getArrayPath($plannedDate, '/hisroomId')[0];
				$eventDate = $this->getArrayPath($plannedDate, '/hisexecutiondate')[0];
				if (in_array($eventRoomId, $requestedRoomIds) && in_array($eventDate, $currentWeek)) {
					$startTime = $this->getArrayPath($plannedDate, '/hisstarttime')[0];
					$endTime = $this->getArrayPath($plannedDate, '/hisendtime')[0];
					$tTables[$eventRoomId][] = array(
						'title' => $name[0],
						'start' => $eventDate . "T" . $startTime,
						'end' => $eventDate . "T" . $endTime
					);
				}
			}
		}
		return $tTables;
	}


	/**
	 * @param $unit int ID of the subject in HisInOne database
	 * @return bool|array false if there was an error otherwise an array with the information about the subject
	 */
	public function readUnit($unit)
	{
		$doc = new DOMDocument('1.0', 'utf-8');
		$doc->formatOutput = true;
		$envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'SOAP-ENV:Envelope');
		$doc->appendChild($envelope);
		if ($this->open) {
			$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ns1', 'http://www.his.de/ws/OpenCourseService');
		} else {
			$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ns1', 'http://www.his.de/ws/CourseService');
			$header = $this->getHeader($doc);
			$envelope->appendChild($header);
		}
		//body of the request
		$body = $doc->createElement('SOAP-ENV:Body');
		$envelope->appendChild($body);
		$readUnit = $doc->createElement('ns1:readUnit');
		$body->appendChild($readUnit);
		$readUnit->appendChild($doc->createElement('ns1:unitId', $unit));

		$soap_request = $doc->saveXML();
		$response1 = $this->postToServer($soap_request, "readUnit");
		if ($response1 === false) {
			return false;
		}
		$response2 = $this->xmlStringToArray($response1);
		if ($response2 === false)
			return false;
		if (!isset($response2['soapenvBody'])) {
			$this->error = 'findUnit(' . $unit . '): Backend reply is missing element soapenvBody';
			return false;
		}
		if (isset($response2['soapenvBody']['soapenvFault'])) {
			$this->error = 'SOAP-Fault' . $response2['soapenvBody']['soapenvFault']['faultcode'] . " " . $response2['soapenvBody']['soapenvFault']['faultstring'];
			return false;
		}
		return $this->getArrayPath($response2, '/soapenvBody/hisreadUnitResponse/hisunit');
	}

	/**
	 * @return array with days of the current week in datetime format
	 */
	private function getCurrentWeekDates()
	{
		$returnValue = array();
		$startDate = time();
		for ($i = 0; $i <= 7; $i++) {
			$returnValue[] = date('Y-m-d', strtotime("+{$i} day 12:00", $startDate));
		}
		return $returnValue;
	}

}
