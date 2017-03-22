<?php

class CourseBackend_HisInOne extends CourseBackend
{
	private $username;
	private $password;
	private $open;


	public function setCredentials($data, $url, $serverID)
	{
		if (array_key_exists('password', $data) && array_key_exists('username', $data) && array_key_exists('role', $data) && isset($data['open'])) {
			$this->error = false;
			$this->password = $data['password'];
			$this->username = $data['username'] . "\t" . $data['role'];
			$this->open = $data['open'];
			if ($url == "") {
				$this->error = true;
				$this->errormsg = "No url is given";
				return !$this->error;
			}
			if ($this->open) {
				$this->location = $url . "/qisserver/services2/OpenCourseService";
			} else {
				$this->location = $url . "/qisserver/services2/CourseService";
			}
			$this->serverID = $serverID;
		} else {
			$this->error = true;
			$this->errormsg = "wrong credentials";
			return false;
		}


		return true;
	}

	public function checkConnection()
	{
		if ($this->location == "") {
			$this->error = true;
			$this->errormsg = "Credentials are not set";
		}
		$this->findUnit(190);
		return !$this->error;
	}

	/**
	 * @param $roomID int
	 * @return array|bool if successful an array with the subjectIDs that take place in the room
	 */
	public function findUnit($roomID)
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
		$termYearN = $doc->createElement('termYear', $termYear);
		$findUnit->appendChild($termYearN);
		$termTypeValueId = $doc->createElement('termTypeValueId', $termType);
		$findUnit->appendChild($termTypeValueId);
		$roomIdN = $doc->createElement('ns1:roomId', $roomID);
		$findUnit->appendChild($roomIdN);

		$soap_request = $doc->saveXML();
		$response1 = $this->__doRequest($soap_request, "findUnit");
		$id = [];
		if ($this->error == true) {
			return false;
		}
		$response2 = $this->toArray($response1);
		if ($response2 === false) {
			return false;
		}
		if (isset($response2['soapenvBody']['soapenvFault'])) {
			$this->error = true;
			$this->errormsg = $response2['soapenvBody']['soapenvFault']['faultcode'] . " " . $response2['soapenvBody']['soapenvFault']['faultstring'];
			return false;
		} elseif ($this->open) {
			$units = $this->getAttributes($response2,'soapenvBody/hisfindUnitResponse/hisunits/hisunit');
			foreach ($units as $unit) {
				$id[] = $unit['hisid'];
			}
		} elseif (!$this->open) {
				$id = $this->getAttributes($response2,'soapenvBody/hisfindUnitResponse/hisunitIds/hisid');
		} else {
			$this->error = true;
			$this->errormsg = "url send a xml in a wrong format";
			$id = false;
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
	private function __doRequest($request, $action)
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
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_URL => $this->location,
			CURLOPT_POSTFIELDS => $request,
			CURLOPT_HTTPHEADER => $header,
		);

		curl_setopt_array($soap_do, $options);

		$output = curl_exec($soap_do);

		if ($output === false) {
			$this->error = true;
			$this->errormsg = 'Curl error: ' . curl_error($soap_do);
		} else {
			$this->error = false;
			$this->errormsg = "";
			///Operation completed successfully
		}
		curl_close($soap_do);
		return $output;
	}

	/**
	 * @param $response xml document
	 * @return bool|array array representation of the xml if possible
	 */
	private function toArray($response)
	{
		try {
			$cleanresponse = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
			$xml = new SimpleXMLElement($cleanresponse);
			$array = json_decode(json_encode((array)$xml), true);
		} catch (Exception $e) {
			$this->error = true;
			$this->errormsg = "url did not send a xml";
			$array = false;
		}
		return $array;
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


	public function getCredentials()
	{
		$credentials = ["username" => "string", "role" =>"string", "password" => "password", "open" => "bool"];
		return $credentials;
	}



	public function fetchSchedulesInternal($param)
	{
		if (empty($param)) {
			$this->error = true;
			$this->errormsg = 'No roomid was given';
		}
		$tTables = [];
		//get all eventIDs in a given room
		$eventIDs = [];
		foreach ($param as $ID) {
			$unitID = $this->findUnit($ID);
			if ($unitID == false) {
				$this->error = false;
				error_log($this->errormsg);
				continue;
			}
			$eventIDs = array_merge($eventIDs, $unitID);
			$eventIDs = array_unique($eventIDs);
		}
		if (empty($eventIDs)) {
			foreach ($param as $room) {
				$tTables[$room] = [];
			}
			return $tTables;
		}
		$events = [];
		//get all information on each event
		foreach ($eventIDs as $each_event) {
			$event = $this->readUnit(intval($each_event));
			if ($event === false) {
				$this->error = false;
				error_log($this->errormsg);
				continue;
			}
			$events[] = $event;
		}
		$currentWeek = $this->getCurrentWeekDates();
		foreach ($param as $room) {
			$timetable = array();
			//Here I go over the soapresponse
			foreach ($events as $event) {
				$name = $this->getAttributes($event,'/hisunit/hisdefaulttext');
				if($name==false){
					//if HisInOne has no default text then there is no name
					$name = [''];
				}
				$dates = $this->getAttributes($event,'/hisunit/hisplanelements/hisplanelement/hisplannedDates/hisplannedDate/hisindividualDates/hisindividualDate');
				foreach ($dates as $date) {
					$roomID =$this->getAttributes($date,'/hisroomId')[0];
					$datum = $this->getAttributes($date,'/hisexecutiondate')[0];
					if (intval($roomID) == $room && in_array($datum, $currentWeek)) {
						$startTime = $this->getAttributes($date,'hisstarttime')[0];
						$endTime = $this->getAttributes($date,'hisendtime')[0];
						$json = array(
							'title' => $name[0],
							'start' => $datum . " " . $startTime,
							'end' => $datum . " " . $endTime
						);
						array_push($timetable, $json);
					}
				}
			}
			$tTables[$room] = $timetable;
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
		$unitId = $doc->createElement('ns1:unitId', $unit);
		$readUnit->appendChild($unitId);

		$soap_request = $doc->saveXML();
		$response1 = $this->__doRequest($soap_request, "readUnit");
		if ($response1 == false) {
			return false;
		}
		$response2 = $this->toArray($response1);
		if ($response2 != false) {
			if (isset($response2['soapenvBody']['soapenvFault'])) {
				$this->error = true;
				$this->errormsg = 'SOAP-Fault'.$response2['soapenvBody']['soapenvFault']['faultcode'] . " " . $response2['soapenvBody']['soapenvFault']['faultstring'];
				return false;
			} elseif (isset($response2['soapenvBody']['hisreadUnitResponse'])) {
				$this->error = false;
				$response3 = $response2['soapenvBody']['hisreadUnitResponse'];
				$this->errormsg = '';
				return $response3;
			} else {
				$this->error = true;
				$this->errormsg = "wrong url or the url send a xml in the wrong format";
				return false;
			}
		}
		return false;
	}

	/**
	 * @return array with days of the current week in datetime format
	 */
	private function getCurrentWeekDates()
	{
		$DateArray = array();
		$startdate = strtotime('Now');
		for ($i = 0; $i <= 7; $i++) {
			$DateArray[] = date('Y-m-d', strtotime("+ {$i} day", $startdate));
		}
		return $DateArray;
	}

}
