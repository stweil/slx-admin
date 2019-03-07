<?php

class CourseBackend_Davinci extends CourseBackend
{

	private $location;
	private $verifyHostname = true;
	private $verifyCert = true;
	/**
	 * @var bool|resource
	 */
	private $curlHandle = false;

	public function setCredentialsInternal($data)
	{
		if (empty($data['baseUrl'])) {
			$this->addError("No url is given", true);
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
			$this->addError("Credentials are not set", true);
			return false;
		}
		$startDate = new DateTime('today 0:00');
		$endDate = new DateTime('+7 days 0:00');
		$data = $this->fetchRoomRaw('someroomid123', $startDate, $endDate);
		if ($data !== false && strpos($data, 'DAVINCI SERVER') === false) {
			$this->addError("Unknown reply; this doesn't seem to be a DAVINCI server.", true);
			return false;
		}
		return true;
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
	 * @param string $roomId unique name of the room, as used by davinci
	 * @param \DateTime $startDate start date to fetch
	 * @param \DateTime $endDate end date of range to fetch
	 * @return array|bool if successful the arrayrepresentation of the timetable
	 */
	private function fetchRoomRaw($roomId, $startDate, $endDate)
	{
		$url = $this->location . "content=xml&type=room&name=" . urlencode($roomId)
			. "&startdate=" . $startDate->format('d.m.Y') . "&enddate=" . $endDate->format('d.m.Y');
		if ($this->curlHandle === false) {
			$this->curlHandle = curl_init();
		}
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYHOST => $this->verifyHostname ? 2 : 0,
			CURLOPT_SSL_VERIFYPEER => $this->verifyCert ? 1 : 0,
			CURLOPT_URL => $url,
		);

		curl_setopt_array($this->curlHandle, $options);
		$output = curl_exec($this->curlHandle);
		if ($output === false) {
			$this->addError('Curl error: ' . curl_error($this->curlHandle), true);
			return false;
		}
		return $output;

	}

	public function fetchSchedulesInternal($requestedRoomIds)
	{
		$startDate = new DateTime('today 0:00');
		$endDate = new DateTime('+7 days 0:00');
		$lower = (int)$startDate->format('Ymd');
		$upper = (int)$endDate->format('Ymd');
		$schedules = [];
		foreach ($requestedRoomIds as $roomId) {
			$return = $this->fetchRoomRaw($roomId, $startDate, $endDate);
			if ($return === false) {
				continue;
			}
			$return = $this->xmlStringToArray($return, $err);
			if ($return === false) {
				$this->addError("Parsing room $roomId XML: $err", false);
				continue;
			}
			$lessons = $this->getArrayPath($return, '/Lessons/Lesson');
			if ($lessons === false) {
				$this->addError("Cannot find /Lessons/Lesson in XML", false);
				continue;
			}
			$timetable = [];
			foreach ($lessons as $lesson) {
				if (!isset($lesson['Date']) || !isset($lesson['Start']) || !isset($lesson['Finish'])) {
					$this->addError('Lesson is missing Date, Start or Finish', false);
					continue;
				}
				$c = (int)$lesson['Date'];
				if ($c < $lower || $c > $upper)
					continue;
				$date = $lesson['Date'];
				$date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
				$start = $lesson['Start'];
				$start = substr($start, 0, 2) . ':' . substr($start, 2, 2);
				$end = $lesson['Finish'];
				$end = substr($end, 0, 2) . ':' . substr($end, 2, 2);
				$subject = isset($lesson['Subject']) ? $lesson['Subject'] : '???';
				$timetable[] = array(
					'title' => $subject,
					'start' => $date . "T" . $start . ':00',
					'end' => $date . "T" . $end . ':00'
				);
			}
			$schedules[$roomId] = $timetable;
		}
		return $schedules;
	}

	public function __destruct()
	{
		if ($this->curlHandle !== false) {
			curl_close($this->curlHandle);
		}
	}
}
