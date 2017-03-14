<?php

class CourseBackend_HisInOne extends CourseBackend
{
	private $username;
	private $password;
	private $open;

	//Sets the location and the login information of this client
	public function setCredentials($data, $location, $serverID)
	{
		if (array_key_exists('password', $data) && array_key_exists('username', $data) && array_key_exists('role', $data) && isset($data['open'])) {
			$this->error = false;
			$this->password = $data['password'];
			$this->username = $data['username'] . "\t" . $data['role'];
			$this->open = $data['open'];
			if ($location == "") {
				$this->error = true;
				$this->errormsg = "No url is given";
				return !$this->error;
			}
			if ($this->open) {
				$this->location = $location . "/qisserver/services2/OpenCourseService";
			} else {
				$this->location = $location . "/qisserver/services2/CourseService";
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
		if ($this->location =="") {
			$this->error = true;
			$this->errormsg = "Credentials are not set";
		}
		$this->findUnit(42);
		return !$this->error;
	}

	//Cache the timetables for 30 minutes ttables older than 60 are not refreshed

	public function findUnit($roomID)
	{
		$termYear = date('Y');
		$termType = date('n');
		if ($termType > 3 && $termType < 10) {
			$termType = 2;
		} elseif ($termType > 10) {
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
		}
		elseif ($this->open && isset($response2['soapenvBody']['hisfindUnitResponse']['hisunits']['hisunit'])) {
			$units = $response2['soapenvBody']['hisfindUnitResponse']['hisunits']['hisunit'];
			foreach ($units as $unit) {
				$id[] = $unit['hisid'];
			}
		} elseif (!$this->open && isset($response2['soapenvBody']['hisfindUnitResponse']['hisunitIds'])) {
			if(isset($response2['soapenvBody']['hisfindUnitResponse']['hisunitIds']['hisid'])){
				$id = $response2['soapenvBody']['hisfindUnitResponse']['hisunitIds']['hisid'];
			}
		}  else {
			$this->error = true;
			$this->errormsg = "url send a xml in a wrong format";
			$id = false;
		}
		return $id;
	}

	//ttables older than 60 minutes are not refreshed

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

	//Contstructs the Soap Header $doc is a DOMDocument this returns a DOMElement

	public function getCacheTime()
	{
		return 30 * 60;
	}

	//returns the IDs in an array for a given roomID or false if there was an error

	public function getRefreshTime()
	{
		return 60 * 60;
	}

	//This function sends a Soaprequest with the eventID and returns an array which contains much
	// informations, like start and enddates for events and their name. It returns false if there was an error

	public function getDisplayName()
	{
		return "HisInOne";
	}


	//Makes a SOAP-Request as a normal POST

	public function getCredentials()
	{
		$credentials = ["username" => "string", "role" => "string", "password" => "string", "open" => "bool"];
		return $credentials;
	}


	//this function transforms a xml string into an array or return false if there was an error

	public function fetchSchedulesInternal($param)
	{
		if(empty($param)){
			$this->error = true;
			$this->errormsg = 'No roomid was given';
		}
		$tTables = [];
		//get all eventIDs in a given room
		$eventIDs = [];
		foreach ($param as $ID) {
			$unitID = $this->findUnit($ID);
			if ($unitID === false) {
				return false;
			}
			$eventIDs = array_merge($eventIDs, $unitID);
			$eventIDs = array_unique($eventIDs);
			if ($this->error == true) {
				return false;
			}
		}
		if(empty($eventIDs)){
			foreach ($param as $room){
				$tTables[$room] = [];
			}
			return $tTables;
		}
		$events = [];
		//get all information on each event
		foreach ($eventIDs as $each_event) {
			$event = $this->readUnit(intval($each_event));
			if ($event === false) {
				return false;
			}
			$events[] = $event;
		}
		$currentWeek = $this->getCurrentWeekDates();
		foreach ($param as $room) {
			$timetable = array();
			//Here I go over the soapresponse
			foreach ($events as $event) {
				if(empty($event['hisunit']['hisplanelements']['hisplanelement'][0]['hisdefaulttext'])){
					$this->error = true;
					$this->errormsg = "url returns a wrong xml";
					return false;
				}
				$title = $event['hisunit']['hisplanelements']['hisplanelement'][0]['hisdefaulttext'];
				foreach ($event as $subject) {
					$units = $subject['hisplanelements']['hisplanelement'];
					foreach ($units as $unit) {
						$pdates = $unit['hisplannedDates']['hisplannedDate'];
						//there seems to be a bug that gives more than one individualDates in plannedDate
						//this construction catches it
						if (array_key_exists('hisindividualDates', $pdates)) {
							$dates = $pdates['hisindividualDates']['hisindividualDate'];
							foreach ($dates as $date) {
								$roomID = $date['hisroomId'];
								$datum = $date['hisexecutiondate'];
								if (intval($roomID) == $room && in_array($datum, $currentWeek)) {
									$startTime = $date['hisstarttime'];
									$endTime = $date['hisendtime'];
									$json = array(
										'title' => $title,
										'start' => $datum . " " . $startTime,
										'end' => $datum . " " . $endTime
									);
									array_push($timetable, $json);
								}
							}
						} else {
							foreach ($pdates as $dates2) {
								$dates = $dates2['hisindividualDates']['hisindividualDate'];
								foreach ($dates as $date) {
									$roomID = $date['hisroomId'];
									$datum = $date['hisexecutiondate'];
									if (intval($roomID) == $room && in_array($datum, $currentWeek)) {

										$startTime = $date['hisstarttime'];
										$endTime = $date['hisendtime'];
										$json = array(
											'title' => $title,
											'start' => $datum . " " . $startTime,
											'end' => $datum . " " . $endTime
										);
										array_push($timetable, $json);
									}
								}
							}
						}
					}
				}
			}
			$tTables[$room] = $timetable;
		}
		return $tTables;
	}

	//Request for a timetable with roomids as array it will be boolean false if there was an error

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
				$this->errormsg = $response2['soapenvBody']['soapenvFault']['faultcode'] . " " . $response2['soapenvBody']['soapenvFault']['faultstring'];
				return false;
			} elseif (isset($response2['soapenvBody']['hisreadUnitResponse'])) {
				$this->error = false;
				$response3 = $response2['soapenvBody']['hisreadUnitResponse'];
				return $response3;
			} else {
				$this->error = true;
				$this->errormsg = "url send a xml in a wrong format";
				return false;
			}
		}
		return false;
	}

	private function getCurrentWeekDates()
	{
		$DateArray = array();
		$startdate = strtotime('-2month');
		for ($i = 0; $i <= 7; $i++) {
			$DateArray[] = date('Y-m-d', strtotime("+ {$i} day", $startdate));
		}
		return $DateArray;
	}

}
