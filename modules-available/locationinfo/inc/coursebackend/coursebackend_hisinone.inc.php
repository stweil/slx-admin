<?php

class CourseBackend_HisInOne extends CourseBackend
{
	private $username;
	private $password;
	private $location;
	private $open;

	//Sets the location and the login information of this client
	public function setCredentials($json, $location, $serverID)
	{
		$this->error = false;
		$data = json_decode($json, true);
		$this->password = $data['password'];
		$this->username = $data['username'] . "\t" . $data['role'];
		$this->open = $data['open'];
		if ($this->open) {
			$this->location = $location . "/qisserver/services2/OpenCourseService";
		} else {
			$this->location = $location . "/qisserver/services2/CourseService";
		}
		$this->serverID = $serverID;
		$this->checkConnection();
		return $this->error;
	}

	public function checkConnection()
	{
		if ($this->location != "") {
			$this->findUnit(42);
			return $this->error;
		}
		$this->error = false;
		$this->errormsg = "No url is given";
		return $this->error;
	}

	//Cache the timetables for 30 minutes ttables older than 60 are not refreshed
	public function getCacheTime()
	{
		return 30 * 60;
	}

	//ttables older than 60 minutes are not refreshed
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
		$credentials = ["username" => "string", "role" => "string", "password" => "string", "open" => "boolean"];
		return $credentials;
	}

	//Contstructs the Soap Header $doc is a DOMDocument this returns a DOMElement
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

	//returns the IDs in an array for a given roomID
	public function findUnit($roomID)
	{
		$termyear = date('Y');
		$termtype = date('n');
		if ($termtype > 3 && $termtype < 10) {
			$termtype = 2;
		} elseif ($termtype > 10) {
			$termtype = 1;
			$termyear = $termyear + 1;
		} else {
			$termtype = 1;
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
		$termYearN = $doc->createElement('termYear', $termyear);
		$findUnit->appendChild($termYearN);
		$termTypeValueId = $doc->createElement('termTypeValueId', $termtype);
		$findUnit->appendChild($termTypeValueId);
		$roomIdN = $doc->createElement('ns1:roomId', $roomID);
		$findUnit->appendChild($roomIdN);

		$soap_request = $doc->saveXML();
		$respons1 = $this->__doRequest($soap_request, "findUnit");
		if ($this->error == true) {
			return $this->errormsg;
		}
		$respons2 = $this->toArray($respons1);
		if (isset($respons2['soapenvBody']['soapenvFault'])) {
			$this->error = true;
			$this->errormsg = $respons2['soapenvBody']['soapenvFault']['faultcode'] . " " . $respons2['soapenvBody']['soapenvFault']['faultstring'];
			return false;
		} else {
			$this->error = false;
		}
		if ($this->open) {
			$units = $respons2['soapenvBody']['hisfindUnitResponse']['hisunits']['hisunit'];
			$id = [];
			foreach ($units as $unit) {
				$id[] = $unit['hisid'];
			}
		} else {
			$id = $respons2['soapenvBody']['hisfindUnitResponse']['hisunitIds']['hisid'];
		}
		return $id;
	}

	//This function sends a Soaprequest with the eventID and returns an array which contains much
	// informations, like start and enddates for events and their name.
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
		$respons1 = $this->__doRequest($soap_request, "readUnit");
		if ($this->error == true) {
			return $this->errormsg;
		}
		$respons2 = $this->toArray($respons1);
		if (isset($respons2['soapenvBody']['soapenvFault'])) {
			$this->error = true;
			$this->errormsg = $respons2['soapenvBody']['soapenvFault']['faultcode'] . " " . $respons2['soapenvBody']['soapenvFault']['faultstring'];
			return false;
		} else {
			$this->error = false;
		}
		$respons3 = $respons2['soapenvBody']['hisreadUnitResponse'];
		return $respons3;
	}

	//Makes a SOAP-Request as a normal POST
	function __doRequest($request, $action)
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
			return 'Curl error: ' . curl_error($soap_do);
		} else {
			$this->error = false;
			$this->errormsg = "";
			///Operation completed successfully
		}
		curl_close($soap_do);
		return $output;
	}

	/**
	 * Request for a timetable
	 *
	 * @param $param int the roomid
	 * @return string the timetable as json
	 */
	public function getJson($param)
	{
		//get all eventIDs in a given room
		$eventIDs = $this->findUnit($param);
		if ($this->error == true) {
			return $this->errormsg;
		}
		//get all information on each event
		$events = [];
		foreach ($eventIDs as $each_event) {
			$events[] = $this->readUnit((int)$each_event);
			if ($this->error == true) {
				return $this->errormsg;
			}
		}
		$timetable = array();
		$currentWeek = $this->getCurrentWeekDates();
		//Here I go over the soapresponse
		try {
			foreach ($events as $event) {
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
								if (intval($roomID) == $param && in_array($datum, $currentWeek)) {

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
									if (intval($roomID) == $param && in_array($datum, $currentWeek)) {

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
			$timetable = json_encode($timetable);
		} catch (Exception $e) {
			$this->error = true;
			$this->errormsg = "url returns a wrong xml";
		}
		return $timetable;
	}

	//this function transforms a xml string into an array
	private function toArray($response)
	{
		$cleanresponse = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
		$xml = new SimpleXMLElement($cleanresponse);
		$array = json_decode(json_encode((array)$xml), true);
		return $array;
	}

	//Request for a timetable with roomids as array
	public function fetchSchedulesInternal($param)
	{
		//get all eventIDs in a given room
		$eventIDs = [];
		foreach ($param as $ID) {
			$eventIDs = array_merge($eventIDs, $this->findUnit($ID));
			var_dump($eventIDs);
			$eventIDs = array_unique($eventIDs);
			if ($this->error == true) {
				return $this->errormsg;
			}
		}
		//get all information on each event
		foreach ($eventIDs as $each_event) {
			$events[] = $this->readUnit(intval($each_event));
			if ($this->error == true) {
				return $this->errormsg;
			}
		}
		$ttables = [];
		$currentWeek = $this->getCurrentWeekDates();
		try {
			foreach ($param as $room) {
				$timetable = array();
				//Here I go over the soapresponse
				foreach ($events as $event) {
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
				$ttables[$room] = json_encode($timetable);
			}
		}
		catch (Exception $e){
			$this->error = true;
			$this->errormsg = "url returns a wrong xml";
		}
		return $ttables;
	}


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
