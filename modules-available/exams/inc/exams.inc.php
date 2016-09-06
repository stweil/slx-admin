<?php

class Exams
{

	/**
	 * @param int[] of location ids. must bot be an associative array.
	 * @return: bool true iff for any of the given location ids an exam is scheduled.
	 **/
	public static function isInExamMode($locationIds, &$lectureId = false, &$autoLogin = false)
	{
		if (!is_array($locationIds)) {
			$locationIds = array($locationIds);
		}
		$l = str_repeat(',?', count($locationIds));
		$res = Database::queryFirst("SELECT lectureid, autologin FROM exams"
			. " INNER JOIN exams_x_location USING (examid)"
			. " WHERE UNIX_TIMESTAMP() BETWEEN starttime AND endtime AND locationid IN (0$l) LIMIT 1", $locationIds);
		if ($res !== false) {
			$lectureId = $res['lectureid'];
			$autoLogin = $res['autologin'];
		}
		return $res !== false;
	}

}
