<?php

class CourseBackend_Davinci extends CourseBackend
{

	private $location;
	private $verifyHostname = true;
	private $verifyCert = true;

	public function setCredentialsInternal($data)
	{
		if (empty($data['baseUrl'])) {
			$this->error = "No url is given";
			return false;
		}
		$location = preg_replace('#/+(davinciis\.dll)?\W*$#i', '', $data['baseUrl']);
		$this->location = $location . "/DAVINCIIS.dll?";
		$this->verifyHostname = $data['verifyHostname'];
		$this->verifyCert = $data['verifyCert'];
		return true;
	}

	public function checkConnection()
	{
		if (empty($this->location)) {
			$this->error = "Credentials are not set";
		} else {
			$data = $this->fetchRoomRaw('someroomid123');
			if (strpos($data, 'DAVINCI SERVER') === false) {
				$this->error = "This doesn't seem to be a DAVINCI server";
			}
		}
		return $this->error === false;
	}

	public function getCredentialDefinitions()
	{
		return [
			new BackendProperty('baseUrl', 'string'),
			new BackendProperty('verifyCert', 'bool', true),
			new BackendProperty('verifyHostname', 'bool', true)
		];
	}

	public function getDisplayName()
	{
		return 'Davinci';
	}

	public function getCacheTime()
	{
		return 30 * 60;
	}

	public function getRefreshTime()
	{
		return 0;
	}

	/**
	 * @param $roomId string name of the room
	 * @return array|bool if successful the arrayrepresentation of the timetable
	 */
	private function fetchRoomRaw($roomId)
	{
		$startDate = new DateTime('today 0:00');
		$endDate = new DateTime('+7 days 0:00');
		$url = $this->location . "content=xml&type=room&name=" . urlencode($roomId)
			. "&startdate=" . $startDate->format('d.m.Y') . "&enddate=" . $endDate->format('d.m.Y');
		$ch = curl_init();
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYHOST => $this->verifyHostname ? 2 : 0,
			CURLOPT_SSL_VERIFYPEER => $this->verifyCert ? 1 : 0,
			CURLOPT_URL => $url,
		);

		curl_setopt_array($ch, $options);
		$output = curl_exec($ch);
		if ($output === false) {
			$this->error = 'Curl error: ' . curl_error($ch);
			return false;
		} else {
			$this->error = false;
			///Operation completed successfully
		}
		curl_close($ch);
		return $output;

	}

	public function fetchSchedulesInternal($requestedRoomIds)
	{
		$schedules = [];
		foreach ($requestedRoomIds as $roomId) {
			$return = $this->fetchRoomRaw($roomId);
			if ($return === false) {
				continue;
			}
			$return = $this->xmlStringToArray($return);
			if ($return === false) {
				continue;
			}
			$lessons = $this->getArrayPath($return, '/Lessons/Lesson');
			if ($lessons === false) {
				$this->error = "Cannot find /Lessons/Lesson in XML";
				continue;
			}
			$timetable = [];
			foreach ($lessons as $lesson) {
				if (!isset($lesson['Date']) || !isset($lesson['Start']) || !isset($lesson['Finish'])) {
					$this->error = 'Lesson is missing Date, Start or Finish';
					continue;
				}
				$date = $lesson['Date'];
				$date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
				$start = $lesson['Start'];
				$start = substr($start, 0, 2) . ':' . substr($start, 2, 2);
				$end = $lesson['Finish'];
				$end = substr($end, 0, 2) . ':' . substr($end, 2, 2);
				$subject = isset($lesson['Subject']) ? $lesson['Subject'] : '???';
				$timetable[] = array(
					'title' => $subject,
					'start' => $date . " " . $start . ':00',
					'end' => $date . " " . $end . ':00'
				);
			}
			$schedules[$roomId] = $timetable;
		}
		return $schedules;
	}
}
