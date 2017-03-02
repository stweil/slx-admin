<?php

class Coursebackend_Davinci extends CourseBackend
{

	private $location;

	public function setCredentials($json, $location, $serverID)
	{
		$this->location = $location . "/DAVINCIIS.dll?";
		$this->serverID = $serverID;
		//Davinci doesn't have credentials
	}

	public function checkConnection()
	{
		$this->fetchSchedulesInternal('B206');
		return $this->error;
	}

	public function getCredentials()
	{
		$return = array();
		return $return;
	}

	public function getDisplayName()
	{
		return 'Davinci';
	}

	public function getCacheTime()
	{
		return 0;
	}

	public function getRefreshTime()
	{
		return 0;
	}

	private function toArray($response)
	{

		$cleanresponse = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
		$xml = new SimpleXMLElement($cleanresponse);
		$array = json_decode(json_encode((array)$xml), true);
		return $array;
	}

	private function fetchArray($roomId)
	{
		$startDate = new DateTime('monday this week');
		$endDate = new DateTime('sunday');
		$url = $this->location . "content=xml&type=room&name=" . $roomId . "&startdate=" . $startDate->format('d.m.Y') . "&enddate=" . $endDate->format('d.m.Y');
		$ch = curl_init();
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_URL => $url,
		);

		curl_setopt_array($ch, $options);
		$output = curl_exec($ch);
		if ($output === false) {
			$this->error = true;
			$this->errormsg = 'Curl error: ' . curl_error($ch);
			return 'Curl error: ' . curl_error($ch);
		} else {
			$this->error = false;
			$this->errormsg = "";
			///Operation completed successfully
		}
		curl_close($ch);
		return $this->toArray($output);

	}

	public function fetchSchedulesInternal($roomIds)
	{
		$schedules = [];
		foreach ($roomIds as $sroomId) {
			$return = $this->fetchArray($sroomId);
			$lessons = $return['Lessons']['Lesson'];
			$timetable = [];
			foreach ($lessons as $lesson) {
				$date = $lesson['Date'];
				$date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
				$start = $lesson['Start'];
				$start = substr($start, 0, 2) . ':' . substr($start, 2, 2);
				$end = $lesson['Finish'];
				$end = substr($end, 0, 2) . ':' . substr($end, 2, 2);
				$subject = $lesson['Subject'];
				$json = array(
					'title' => $subject,
					'start' => $date . " " . $start . ':00',
					'end' => $date . " " . $end . ':00'
				);
				array_push($timetable, $json);
			}
			$timetable = json_encode($timetable);
			$schedules[$sroomId] = $timetable;
		}
		return $schedules;
	}
}

