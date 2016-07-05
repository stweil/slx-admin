<?php

class Exams
{

	/**
	 * @param int[] of location ids. must bot be an associative array.
	 * @return: bool true iff for any of the given location ids an exam is scheduled.
	 **/
	public static function isInExamMode($locationIds)
	{
		if (!is_array($locationIds)) {
			$locationIds = array($locationIds);
		} elseif (empty($locationIds)) {
			return false;
		}
		$l = str_repeat(',?', count($locationIds));
		$res = Database::queryFirst("SELECT examid FROM exams"
			. " INNER JOIN exams_x_location USING (examid)"
			. " WHERE UNIX_TIMESTAMP() BETWEEN starttime AND endtime AND locationid IN (0$l) LIMIT 1", $locationIds);
		return $res !== false;
	}

}
