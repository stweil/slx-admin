<?php

class CourseBackend_Davinci extends CourseBackend
{

	public function setCredentials($data, $location, $serverId)
	{
		if (empty($location)) {
			$this->error = "No url is given";
			return false;
		}
		$this->location = $location . "/DAVINCIIS.dll?";
		$this->serverId = $serverId;
		//Davinci doesn't have credentials
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

	public function getCredentials()
	{
		return array();
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
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
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
			$return = $this->toArray($return);
			if ($return === false) {
				continue;
			}
			$lessons = $this->getAttributes($return, '/Lessons/Lesson');
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
