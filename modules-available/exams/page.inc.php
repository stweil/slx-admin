<?php

class Page_Exams extends Page
{
	var $action = false;
	var $exams;
	var $locations;
	var $lectures;
	private $currentExam;
	private $rangeMin;
	private $rangeMax;


	/** if examid is set, also add a column 'selected' **/
	protected function readLocations($examidOrLocations = null)
	{
		if ($examidOrLocations == null) {
			$active = 0;
		} elseif (is_array($examidOrLocations)) {
			$active = $examidOrLocations;
		} else {
			$tmp = Database::simpleQuery("SELECT locationid FROM exams_x_location WHERE examid= :examid", array('examid' => $examidOrLocations));
			$active = array();
			while ($row = $tmp->fetch(PDO::FETCH_ASSOC)) {
				$active[] = (int)$row['locationid'];
			}
		}
		$this->locations = Location::getLocations($active);
	}

	protected function readExams()
	{
		$tmp = Database::simpleQuery("SELECT e.examid, e.autologin, l.displayname AS lecturename, e.starttime, e.endtime, e.description, GROUP_CONCAT(exl.locationid) AS locationids, "
			. "GROUP_CONCAT(loc.locationname SEPARATOR ', ') AS locationnames FROM exams e "
			. "NATURAL LEFT JOIN exams_x_location exl "
			. "NATURAL LEFT JOIN location loc "
			. "LEFT JOIN sat.lecture l USING (lectureid) "
			. "GROUP BY examid ");
		while ($exam = $tmp->fetch(PDO::FETCH_ASSOC)) {
			$this->exams[] = $exam;
		}
	}

	protected function readLectures()
	{
		$tmp = Database::simpleQuery(
			"SELECT lectureid, Group_Concat(locationid) as lids, displayname, starttime, endtime, isenabled, firstname, lastname, email " .
			"FROM sat.lecture " .
			"INNER JOIN sat.user ON (user.userid = lecture.ownerid) " .
			"NATURAL LEFT JOIN sat.lecture_x_location " .
			"WHERE isexam <> 0 AND starttime < :rangeMax AND endtime > :rangeMin " .
			"GROUP BY lectureid",
			['rangeMax' => $this->rangeMax, 'rangeMin' => $this->rangeMin]);
		while ($lecture = $tmp->fetch(PDO::FETCH_ASSOC)) {
			$this->lectures[] = $lecture;
		}
	}

	protected function makeItemsForVis()
	{
		$out = [];
		// foreach group also add an invisible item on top
		// disabled for now - more of an annoyance if you have more than a few rooms
		/*
		foreach ($this->locations as $l) {
			$out[] = ['id' => 'spacer_' . $l['locationid'],
				'group' => $l['locationid'],
				'className' => 'spacer',
				'start' => 0,
				'content' => 'spacer',
				'end' => 99999999999999,
				'subgroup' => 0
			];
		}
		*/
		$unique_ids = 1;
		/* add the red shadows */
		if (is_array($this->exams)) {
			foreach ($this->exams as $e) {
				if ($e['starttime'] > $this->rangeMax || $e['endtime'] < $this->rangeMin)
					continue;
				$locationids = explode(',', $e['locationids']);
				if ($locationids[0] == 0) {
					$locationids = [];
					foreach ($this->locations as $location) {
						$locationids[] = $location['locationid'];
					}
				}
				foreach ($locationids as $locationid) {
					$out[] = [
						'id' => 'shadow_' . $unique_ids++,
						'content' => $e['description'],
						'title' => $e['description'],
						'start' => intval($e['starttime']) * 1000,
						'end' => intval($e['endtime']) * 1000,
						'type' => 'background',
						'group' => $locationid,
					];
				}
			}
		}
		/* add the lectures */
		$allLocationIds = array_map(function($loc) { return $loc['locationid']; }, $this->locations);
		$i = 2;
		foreach ($this->lectures as $lecture) {
			$mark = '<span class="' . ($lecture['isenabled'] ? '' : 'glyphicon glyphicon-exclamation-sign') . '"></span>';
			if (empty($lecture['lids'])) {
				$locations = $allLocationIds;
			} else {
				$locations = explode(',', $lecture['lids']);
			}
			foreach ($locations as $location) {
				$out[] = [
					'id' => $lecture['lectureid'] . '/' . $location,
					'content' => htmlspecialchars($lecture['displayname']) . $mark,
					'title' => $lecture['isenabled'] ? '' : Dictionary::translate('warning_lecture_is_not_enabled'),
					'start' => intval($lecture['starttime']) * 1000,
					'end' => intval($lecture['endtime']) * 1000,
					'group' => $location,
					'className' => $lecture['isenabled'] ? '' : 'disabled',
					'editable' => false,
					'subgroup' => $i++,
				];
			}
		}

		return json_encode($out);
	}

	protected function makeGroupsForVis()
	{
		$out = [];
		foreach ($this->locations as $l) {
			$out[] = [
				'id' => $l['locationid'],
				'content' => $l['locationpad'] . ' ' . $l['locationname'],
				'sortIndex' => $l['sortIndex'],
			];
		}
		return json_encode($out);
	}

	protected function makeExamsForTemplate()
	{
		$out = [];
		$now = time();
		if (is_array($this->exams)) {
			foreach ($this->exams as $exam) {
				if ($exam['endtime'] < $now) {
					$exam['rowClass'] = 'text-muted';
					$exam['btnClass'] = 'btn-success';
					$exam['liesInPast'] = true;
				} else {
					$exam['btnClass'] = 'btn-default';
				}
				$exam['starttime_s'] = date('Y-m-d H:i', $exam['starttime']);
				$exam['endtime_s'] = date('Y-m-d H:i', $exam['endtime']);
				$out[] = $exam;
			}
		}
		return $out;
	}

	protected function makeLectureExamList()
	{
		$out = [];
		$now = time();
		$cutoff = strtotime('+30 day');
		$theCount = 0;
		foreach ($this->lectures as $lecture) {
			if ($lecture['endtime'] < $now || $lecture['starttime'] > $cutoff)
				continue;
			$entry = $lecture;
			if (!$lecture['isenabled']) {
				$entry['class'] = 'text-muted';
			}
			$entry['starttime_s'] = date('Y-m-d H:i', $lecture['starttime']);
			$entry['endtime_s'] = date('Y-m-d H:i', $lecture['endtime']);
			$duration = $lecture['endtime'] - $lecture['starttime'];
			if ($duration < 86400) {
				$entry['duration_s'] = gmdate('H:i', $duration);
			}
			if (++$theCount > 5) {
				$entry['class'] = 'collapse';
			}
			$out[] = $entry;
		}
		return $out;
	}
	
	protected function makeEditFromArray($source)
	{
		if (!isset($source['description']) && isset($source['displayname'])) {
			$source['description'] = $source['displayname'];
		}
		return [
			'starttime_date' => date('Y-m-d', $source['starttime']),
			'starttime_time' => date('H:i', $source['starttime']),
			'endtime_date' => date('Y-m-d', $source['endtime']),
			'endtime_time' => date('H:i', $source['endtime'])
		] + $source;
	}

	private function isDateSane($time)
	{
		return ($time >= $this->rangeMin && $time <= $this->rangeMax);
	}

	private function saveExam()
	{
		if (!Request::isPost()) {
			Util::traceError('Is not post');
		}
		/* process form-data */
		$locationids = Request::post('locations', [], "ARRAY");

		/* global room has id 0 */
		if (empty($locationids)) {
			$locationids[] = 0;
		}

		$examid = Request::post('examid', 0, 'int');
		$starttime = strtotime(Request::post('starttime_date') . " " . Request::post('starttime_time'));
		$endtime = strtotime(Request::post('endtime_date') . " " . Request::post('endtime_time'));
		$description = Request::post('description', '', 'string');
		$lectureid = Request::post('lectureid', '', 'string');
		$autologin = Request::post('autologin', '', 'string');
		if (!$this->isDateSane($starttime)) {
			Message::addError('starttime-invalid', Request::post('starttime_date') . " " . Request::post('starttime_time'));
			Util::redirect('?do=exams');
		}
		if (!$this->isDateSane($endtime)) {
			Message::addError('endtime-invalid', Request::post('endtime_date') . " " . Request::post('endtime_time'));
			Util::redirect('?do=exams');
		}
		if ($endtime <= $starttime) {
			Message::addError('end-before-start');
			Util::redirect('?do=exams');
		}

		if ($examid === 0) {
			// No examid given, is add
			$res = Database::exec("INSERT INTO exams(lectureid, starttime, endtime, autologin, description) VALUES(:lectureid, :starttime, :endtime, :autologin, :description);",
					compact('lectureid', 'starttime', 'endtime', 'autologin', 'description')) !== false;

			$exam_id = Database::lastInsertId();
			foreach ($locationids as $lid) {
				$res = $res && Database::exec("INSERT INTO exams_x_location(examid, locationid) VALUES(:exam_id, :lid)", compact('exam_id', 'lid')) !== false;
			}
			if ($res === false) {
				Message::addError('exam-not-added');
			} else {
				Message::addInfo('exam-added-success');
			}
			Util::redirect('?do=exams');
		}

		// Edit

		$this->currentExam = Database::queryFirst("SELECT * FROM exams WHERE examid = :examid", array('examid' => $examid));
		if ($this->currentExam === false) {
			Message::addError('invalid-exam-id', $examid);
			Util::redirect('?do=exams');
		}

		/* update fields */
		$res = Database::exec("UPDATE exams SET lectureid = :lectureid, starttime = :starttime, endtime = :endtime, autologin = :autologin, description = :description WHERE examid = :examid",
				compact('lectureid', 'starttime', 'endtime', 'description', 'examid', 'autologin')) !== false;
		/* drop all connections and reconnect to rooms */
		$res = $res && Database::exec("DELETE FROM exams_x_location WHERE examid = :examid", compact('examid')) !== false;
		/* reconnect */
		foreach ($locationids as $lid) {
			$res = $res && Database::exec("INSERT INTO exams_x_location(examid, locationid) VALUES(:examid, :lid)", compact('examid', 'lid')) !== false;
		}
		if ($res !== false) {
			Message::addInfo("changes-successfully-saved");
		} else {
			Message::addError("error-while-saving-changes");
		}
		Util::redirect('?do=exams');
	}

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$this->rangeMin = strtotime('-1 day');
		$this->rangeMax = strtotime('+3 month');

		$req_action = Request::any('action', 'show');
		if (in_array($req_action, ['show', 'add', 'delete', 'edit', 'save'])) {
			$this->action = $req_action;
		}

		if ($this->action === 'show') {

			$this->readExams();
			$this->readLocations();
			$this->readLectures();

		} elseif ($this->action === 'add') {

			$this->readLectures();

		} elseif ($this->action === 'edit') {

			$examid = Request::get('examid', 0, 'int');
			$this->currentExam = Database::queryFirst("SELECT * FROM exams WHERE examid = :examid", array('examid' => $examid));
			if ($this->currentExam === false) {
				Message::addError('invalid-exam-id', $examid);
				Util::redirect('?do=exams');
			}
			$this->readLocations($examid);
			$this->readLectures();

		} elseif ($this->action === 'save') {

			$this->saveExam();

		} elseif ($this->action === 'delete') {

			if (!Request::isPost()) {
				die('delete only works with a post request');
			}
			$examid = Request::post('examid');
			$res = Database::exec("DELETE FROM exams WHERE examid = :examid;", compact('examid'));
			$res = Database::exec("DELETE FROM exams_x_location WHERE examid = :examid;", compact('examid'));
			if ($res === false) {
				Message::addError('exam-not-deleted-error');
			} else {
				Message::addInfo('exam-deleted-success');
			}
			Util::redirect('?do=exams');

		} elseif ($this->action === false) {

			Util::traceError("action not implemented");

		}
	}

	protected function doRender()
	{
		if ($this->action === "show") {

			// General title and description
			Render::addTemplate('page-main-heading');
			// List of defined exam periods
			Render::addTemplate('page-exams', [
					'exams' => $this->makeExamsForTemplate()
			]);
			// List of upcoming lectures marked as exam
			$upcoming = $this->makeLectureExamList();
			if (empty($upcoming)) {
				Message::addInfo('no-upcoming-lecture-exams');
			} else {
				Render::addTemplate('page-upcoming-lectures', [
					'pending_lectures' => $upcoming,
					'decollapse' => array_key_exists('class', end($upcoming))
				]);
			}
			// Vis.js timeline
			Render::addTemplate('page-exams-vis', [
				'exams_json' => $this->makeItemsForVis(),
				'rooms_json' => $this->makeGroupsForVis(),
				'vis_begin' => strtotime('-5 minute') * 1000,
				'vis_end' => strtotime('+2 day') * 1000,
				'vis_min_date' => $this->rangeMin * 1000,
				'vis_max_date' => $this->rangeMax * 1000,
				'axis_label' => (count($this->locations) > 5 ? 'both' : 'bottom'),
				'utc_offset' => date('P')
			]);

		} elseif ($this->action === "add") {

			Render::setTitle(Dictionary::translate('title_add-exam'));
			$data = [];
			$baseLecture = Request::any('lectureid', false, 'string');
			$locations = null;
			if ($baseLecture !== false) {
				foreach ($this->lectures as &$lecture) {
					if ($lecture['lectureid'] === $baseLecture) {
						$data['exam'] = $this->makeEditFromArray($lecture);
						$locations = explode(',', $lecture['lids']);
						$lecture['selected'] = 'selected';
						break;
					}
				}
				unset($lecture);
			}
			$data['lectures'] = $this->lectures;
			$this->readLocations($locations);
			$data['locations'] = $this->locations;
			Render::addTemplate('page-add-edit-exam', $data);

		} elseif ($this->action === 'edit') {

			Render::setTitle(Dictionary::translate('title_edit-exam'));
			$exam = $this->makeEditFromArray($this->currentExam);
			foreach ($this->lectures as &$lecture) {
				if ($lecture['lectureid'] === $this->currentExam['lectureid']) {
					$lecture['selected'] = 'selected';
				}
			}
			Render::addTemplate('page-add-edit-exam', ['exam' => $exam, 'locations' => $this->locations, 'lectures' => $this->lectures]);

		}
	}

}
