<?php

class Page_Exams extends Page
{
	var $action = false;
	var $exams = [];
	var $locations = [];
	var $lectures = [];
	private $currentExam;
	private $rangeMin;
	private $rangeMax;
	private $userEditLocations = [];
	private $userViewLocations = [];


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
			. "GROUP BY examid "
			. "ORDER BY examid ASC");

		while ($exam = $tmp->fetch(PDO::FETCH_ASSOC)) {
			$view = $edit = false;
			// User has permission for all locations
			if (in_array(0, $this->userViewLocations)) {
				$view = true;
			}
			if (in_array(0, $this->userEditLocations)) {
				$edit = true;
			}
			if ($view && $edit) {
				$this->exams[] = $exam;
				continue;
			}
			// Fine grained check by locations
			if ($exam['locationids'] === null) {
				$locationids = [0];
			} else {
				$locationids = explode(',', $exam['locationids']);
			}
			if (!$view && empty(array_intersect($locationids, $this->userViewLocations))) {
				// Not a single location in common, skip
				continue;
			}
			if (!$edit && $this->userCanEditLocation($locationids)) {
				// Only allow edit if user can edit all the locations the exam is assigned to
				$edit = true;
			}
			// Set disabled string
			if (!$edit) {
				$exam['edit']['disabled'] = 'disabled';
			}
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
			"GROUP BY lectureid " .
			"ORDER BY starttime ASC, displayname ASC",
			['rangeMax' => $this->rangeMax, 'rangeMin' => $this->rangeMin]);
		while ($lecture = $tmp->fetch(PDO::FETCH_ASSOC)) {
			$this->lectures[] = $lecture;
		}
	}

	// Initialise the user-permission-based lists
	protected function setUserLocations()
	{
		// all locations the user has permission to edit
		$this->userEditLocations = User::getAllowedLocations("exams.edit");
		$view = User::getAllowedLocations("exams.view");
		// all locations the user can view or edit
		$this->userViewLocations = array_unique(array_merge($this->userEditLocations, $view));
	}

	// returns true if user is allowed to edit the exam
	protected function userCanEditExam($examid = NULL)
	{
		if (in_array(0, $this->userEditLocations)) // Trivial case -- don't query if global perms
			return true;
		if ($examid === null)
			return User::hasPermission('exams.edit');
		// Check locations of existing exam
		$res = Database::simpleQuery("SELECT locationid FROM exams_x_location WHERE examid= :examid", array('examid' => $examid));
		while ($locId = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!in_array($locId['locationid'], $this->userEditLocations))
				return false;
		}
		return true;
	}

	// checks if user is allowed to save an exam with all the locations
	// needs information if it's add (second para = true) or edit (second para = false)
	protected function userCanEditLocation($locationids) {
		return empty(array_diff($locationids, $this->userEditLocations));
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
			if (in_array($l["locationid"], $this->userViewLocations)) {
				$out[] = [
					'id' => $l['locationid'],
					'content' => $l['locationpad'] . ' ' . $l['locationname'],
					'sortIndex' => $l['sortIndex'],
				];
			}
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
					$exam['btnClass'] = 'btn-default';
					$exam['liesInPast'] = true;
				} else {
					$exam['btnClass'] = 'btn-danger';
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

		/* global room is 0/NULL */
		if (empty($locationids)) {
			$locationids[] = 0;
		}

		if (!$this->userCanEditLocation($locationids)) {
			Message::addError('main.no-permission');
			Util::redirect('?do=exams');
		}
		if ($locationids[0] === 0) {
			$locationids[0] = null;
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

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$this->rangeMin = strtotime('-1 day');
		$this->rangeMax = strtotime('+3 month');

		$req_action = Request::any('action', 'show');
		if (in_array($req_action, ['show', 'add', 'delete', 'edit', 'save'])) {
			$this->action = $req_action;
		}

		if (Request::isPost()) {
			$examid = Request::post('examid', 0, 'int');
		} else if (Request::isGet()) {
			$examid = Request::get('examid', 0, 'int');
		} else {
			die('Neither Post nor Get Request send.');
		}

		// initialise user-permission-lists
		$this->setUserLocations();

		if ($this->action === 'show') {

			$this->readExams();
			$this->readLocations();
			$this->readLectures();

		} elseif ($this->action === 'add') {

			User::assertPermission('exams.edit');
			$this->readLectures();

		} elseif ($this->action === 'edit') {

			if (!$this->userCanEditExam($examid)) {
				Message::addError('main.no-permission');
				Util::redirect('?do=exams');
			}
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

			if (!$this->userCanEditExam($examid)) {
				Message::addError('main.no-permission');
			} else {
				$res1 = Database::exec("DELETE FROM exams WHERE examid = :examid;", compact('examid'));
				$res2 = Database::exec("DELETE FROM exams_x_location WHERE examid = :examid;", compact('examid'));
				if ($res1 === false || $res2 === false) {
					Message::addError('exam-not-deleted-error');
				} else {
					Message::addInfo('exam-deleted-success');
				}
			}

			Util::redirect('?do=exams');

		} elseif ($this->action === false) {

			Util::traceError("action not implemented");

		}
	}

	protected function doRender()
	{
		if ($this->action === "show") {

			User::assertPermission('exams.view');
			// General title and description
			Render::addTemplate('page-main-heading');
			// List of defined exam periods
			$params = ['exams' => $this->makeExamsForTemplate()];
			Permission::addGlobalTags($params['perms'], NULL, ['exams.edit']);
			Render::addTemplate('page-exams', $params);
			// List of upcoming lectures marked as exam
			$upcoming = $this->makeLectureExamList();
			if (empty($upcoming)) {
				Message::addInfo('no-upcoming-lecture-exams');
			} else {
				Render::addTemplate('page-upcoming-lectures', [
					'pending_lectures' => $upcoming,
					'allowedToAdd' => $this->userCanEditExam(),
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

			$this->readLocations($locations);
			$data['lectures'] = $this->lectures;
			$data['locations'] = $this->locations;

			// if user has no permission to add for this location, disable the location in the select
			foreach ($data['locations'] as &$loc) {
				if (!in_array($loc["locationid"], $this->userEditLocations)) {
					$loc["disabled"] = "disabled";
				}
			}

			Render::addTemplate('page-add-edit-exam', $data);

		} elseif ($this->action === 'edit') {

			Render::setTitle(Dictionary::translate('title_edit-exam'));
			$exam = $this->makeEditFromArray($this->currentExam);
			foreach ($this->lectures as &$lecture) {
				if ($lecture['lectureid'] === $this->currentExam['lectureid']) {
					$lecture['selected'] = 'selected';
				}
			}

			$data = [];
			$data['exam'] = $exam;
			$data['locations'] = $this->locations;
			$data['lectures'] = $this->lectures;

			// if user has no permission to edit for this location, disable the location in the select
			foreach ($data['locations'] as &$loc) {
				if (!in_array($loc["locationid"], $this->userEditLocations)) {
					$loc["disabled"] = "disabled";
				}
			}

			Render::addTemplate('page-add-edit-exam', $data);

		}
	}

}
