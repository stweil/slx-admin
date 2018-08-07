<?php

class CourseBackend_HisInOne extends CourseBackend
{
	private $username = '';
	private $password = '';
	private $open = true;
	private $location;
	private $verifyHostname = true;
	private $verifyCert = true;
	/**
	 * @var bool|resource
	 */
	private $curlHandle = false;


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
		$this->username = $data['username'];
		if (!empty($data['role'])) {
			$this->username .= "\t" . $data['role'];
		}
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
			$this->findUnit(123456789, date('Y-m-d'), true);
		}
		return $this->error === false;
	}

	/**
	 * @param int $roomId his in one room id to get
	 * @param bool $connectionCheckOnly true will only check if no soapError is returned, return value will be empty
	 * @return array|bool if successful an array with the event ids that take place in the room
	 */
	public function findUnit($roomId, $day, $connectionCheckOnly = false)
	{
		$doc = new DOMDocument('1.0', 'utf-8');
		$doc->formatOutput = true;
		$envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'SOAP-ENV:Envelope');
		$doc->appendChild($envelope);
		if ($this->open) {
			$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ns1', 'http://www.his.de/ws/OpenCourseService');
		} else {
			$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ns1', 'http://www.his.de/ws/CourseService');
		}
		$header = $this->getHeader($doc);
		$envelope->appendChild($header);
		//Body of the request
		$body = $doc->createElement('SOAP-ENV:Body');
		$envelope->appendChild($body);
		$findUnit = $doc->createElement('ns1:findUnit');
		$body->appendChild($findUnit);
		$findUnit->appendChild($doc->createElement('ns1:individualDatesExecutionDate', $day));
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
			$this->error = 'Backend reply is missing element soapenvBody';
			return false;
		}
		if (isset($response2['soapenvBody']['soapenvFault'])) {
			$this->error = 'SOAP-Fault (' . $response2['soapenvBody']['soapenvFault']['faultcode'] . ") " . $response2['soapenvBody']['soapenvFault']['faultstring'];
			return false;
		}
		// We only need to check if the connection is working (URL ok, credentials ok, ..) so bail out early
		if ($connectionCheckOnly) {
			return array();
		}
		if ($this->open) {
			$path = '/soapenvBody/hisfindUnitResponse/hisunits';
			$subpath = '/hisunit/hisid';
		} else {
			$path = '/soapenvBody/hisfindUnitResponse/hisunitIds';
			$subpath = '/hisid';
		}
		$idSubDoc = $this->getArrayPath($response2, $path);
		if ($idSubDoc === false) {
			$this->error = 'Cannot find ' . $path;
			//@file_put_contents('/tmp/findUnit-1.' . $roomId . '.' . microtime(true), print_r($response2, true));
			return false;
		}
		if (empty($idSubDoc))
			return $idSubDoc;
		$idList = $this->getArrayPath($idSubDoc, $subpath);
		if ($idList === false) {
			$this->error = 'Cannot find ' . $subpath . ' after ' . $path;
			@file_put_contents('/tmp/bwlp-findUnit-2.' . $roomId . '.' . microtime(true), print_r($idSubDoc, true));
		}
		return $idList;
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

		if ($this->curlHandle === false) {
			$this->curlHandle = curl_init();
		}

		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYHOST => $this->verifyHostname ? 2 : 0,
			CURLOPT_SSL_VERIFYPEER => $this->verifyCert ? 1 : 0,
			CURLOPT_URL => $this->location,
			CURLOPT_POSTFIELDS => $request,
			CURLOPT_HTTPHEADER => $header,
		);

		curl_setopt_array($this->curlHandle, $options);

		$output = curl_exec($this->curlHandle);

		if ($output === false) {
			$this->error = 'Curl error: ' . curl_error($this->curlHandle);
		} else {
			$this->error = false;
			///Operation completed successfully
		}
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
		$currentWeek = $this->getCurrentWeekDates();
		$tTables = [];
		//get all eventIDs in a given room
		$eventIds = [];
		foreach ($requestedRoomIds as $roomId) {
			$ok = false;
			foreach ($currentWeek as $day) {
				$roomEventIds = $this->findUnit($roomId, $day, false);
				if ($roomEventIds === false) {
					if ($this->error !== false) {
						error_log('Cannot findUnit(' . $roomId . '): ' . $this->error);
						$this->error = false;
					}
					// TODO: Error gets swallowed
					continue;
				}
				$ok = true;
				$eventIds = array_merge($eventIds, $roomEventIds);
			}
			if ($ok) {
				$tTables[$roomId] = [];
			}
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
				error_log('Cannot readUnit(' . $eventId . '): ' . $this->error);
				$this->error = false;
				// TODO: Error gets swallowed
				continue;
			}
			$eventDetails = array_merge($eventDetails, $event);
		}
		$name = false;
		foreach ($eventDetails as $event) {
			foreach (array('/hisdefaulttext',
							'/hisshorttext',
							'/hisshortcomment') as $path) {
				$name = $this->getArrayPath($event, $path);
				if (!empty($name) && !empty($name[0]))
					break;
				$name = false;
			}
			if ($name === false) {
				$name = ['???'];
			}
			$planElements = $this->getArrayPath($event, '/hisplanelements/hisplanelement');
			if ($planElements === false) {
				$this->error = 'Cannot find ./hisplanelements/hisplanelement';
				error_log('Cannot find ./hisplanelements/hisplanelement');
				error_log(print_r($event, true));
				continue;
			}
			foreach ($planElements as $planElement) {
				$unitPlannedDates = $this->getArrayPath($planElement,
					'/hisplannedDates/hisplannedDate/hisindividualDates/hisindividualDate');
				if ($unitPlannedDates === false) {
					$this->error = 'Cannot find ./hisplannedDates/hisplannedDate/hisindividualDates/hisindividualDate';
					error_log('Cannot find ./hisplannedDates/hisplannedDate/hisindividualDates/hisindividualDate');
					error_log(print_r($planElement, true));
					continue;
				}
				$localName = $this->getArrayPath($planElement, '/hisdefaulttext');
				if ($localName === false || empty($localName[0])) {
					$localName = $name;
				}
				foreach ($unitPlannedDates as $plannedDate) {
					$eventRoomId = $this->getArrayPath($plannedDate, '/hisroomId')[0];
					$eventDate = $this->getArrayPath($plannedDate, '/hisexecutiondate')[0];
					if (in_array($eventRoomId, $requestedRoomIds) && in_array($eventDate, $currentWeek)) {
						$startTime = $this->getArrayPath($plannedDate, '/hisstarttime')[0];
						$endTime = $this->getArrayPath($plannedDate, '/hisendtime')[0];
						$tTables[$eventRoomId][] = array(
							'title' => $localName[0],
							'start' => $eventDate . "T" . $startTime,
							'end' => $eventDate . "T" . $endTime
						);
					}
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
		}
		$header = $this->getHeader($doc);
		$envelope->appendChild($header);
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
			$this->error = 'Backend reply is missing element soapenvBody';
			return false;
		}
		if (isset($response2['soapenvBody']['soapenvFault'])) {
			$this->error = 'SOAP-Fault (' . $response2['soapenvBody']['soapenvFault']['faultcode'] . ") " . $response2['soapenvBody']['soapenvFault']['faultstring'];
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
		for ($i = 0; $i < 7; $i++) {
			$returnValue[] = date('Y-m-d', strtotime("+{$i} day 12:00", $startDate));
		}
		return $returnValue;
	}

	public function __destruct()
	{
		if ($this->curlHandle !== false) {
			curl_close($this->curlHandle);
		}
	}

}
