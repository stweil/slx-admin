<?php

class Page_Exams extends Page
{
    var $action = false;
    var $exams;
    var $locations;
    var $lectures;
    private $currentExam;


    /** if examid is set, also add a column 'selected' **/
    protected function readLocations($examid = NULL)
    {
        if ($examid == NULL) {
            $tmp = Database::simpleQuery("SELECT locationid, locationname FROM location;", []);
        } else {
            $tmp = Database::simpleQuery("SELECT locationid, locationname, " .
                "EXISTS(SELECT * FROM exams NATURAL JOIN exams_x_location WHERE locationid = x.locationid AND examid= :examid) AS selected FROM location x", compact('examid'));
        }
        while ($loc = $tmp->fetch(PDO::FETCH_ASSOC)) {
            $this->locations[] = $loc;
        }
    }

    protected function readExams()
    {
        $tmp = Database::simpleQuery("select examid, starttime, endtime, description, GROUP_CONCAT(locationid) AS locationids, "
            . "GROUP_CONCAT(locationname) AS locationnames FROM "
            . "exams NATURAL LEFT JOIN exams_x_location NATURAL LEFT JOIN location GROUP BY examid", []);
        while ($exam = $tmp->fetch(PDO::FETCH_ASSOC)) {
            $this->exams[] = $exam;
        }
    }

    protected function readLectures()
    {
        $tmp = Database::simpleQuery(
            "SELECT lectureid, locationid, displayname, starttime, endtime, isenabled ".
            "FROM sat.lecture NATURAL JOIN sat.lecture_x_location " .
            "WHERE isexam <> 0");
        while ($lecture = $tmp->fetch(PDO::FETCH_ASSOC)) {
            $this->lectures[] = $lecture;
        }
    }

    protected function makeItemsForVis()
    {
        $out = [];
        /* foreach group also add an invisible item on top */
        foreach ($this->locations as $l) {
            $out[] = [  'id' => 'spacer_' . $l['locationid'],
                        'group' => $l['locationid'],
                        'className' => 'spacer',
                        'start'  => 0,
                        'content' => 'spacer',
                        'end'       => 99999999999999,
                        'subgroup' => 0
                    ];

        }
        $unique_ids = 1;
        /* add the red shadows */
        foreach ($this->exams as $e) {
            foreach(explode(',', $e['locationids']) as $locationid) {
                $out[] = [ 'id'         => 'shadow_' . $unique_ids++,
                           'content'    => '',
                           'start'      => intval($e['starttime']) * 1000,
                           'end'        => intval($e['endtime']) * 1000,
                           'type'       => 'background',
                           'group'      => $locationid,
                       ];
                }
        }
        /* add the lectures */
        $i = 2;
        foreach ($this->lectures as $l) {
            $mark = '<span class="' . ($l['isenabled'] ? '' : 'glyphicon glyphicon-exclamation-sign') . '"></span>';
            $out[] = [
                'id'        => $l['lectureid'] . '/' . $l['locationid'],
                'content'   => htmlspecialchars($l['displayname']) . $mark ,
                'title'     => $l['isenabled'] ? '' : Dictionary::translate('warning_lecture_is_not_enabled'),
                'start'     => intval($l['starttime']) * 1000,
                'end'       => intval($l['endtime']) * 1000,
                'group'     => $l['locationid'],
                'className' => $l['isenabled'] ? '' : 'disabled',
                'editable'  => false,
                'subgroup'  => $i++
            ];

        }

        return json_encode($out);
    }

    protected function makeGroupsForVis()
    {
        $out = [];
        foreach ($this->locations as $l) {
            $out[] = [
               'id'             => $l['locationid'],
               'content'        => $l['locationname']
           ];
        }
        return json_encode($out);
    }

    protected function makeExamsForTemplate() {
        $out = [];
        foreach ($this->exams as $exam) {
            $tmp = $exam;
            $tmp['starttime'] = date('Y-m-d H:i', $tmp['starttime']);
            $tmp['endtime'] = date('Y-m-d H:i', $tmp['endtime']);
            $out[] = $tmp;
        }
        return $out;
    }

    private function dateSane($time)
    {
        if ($time < strtotime('-1 day'))
            return false;
        if ($time > strtotime('+90 day'))
            return false;
        return true;
    }

    private function saveExam()
    {
        if (!Request::isPost()) {
            Util::traceError('Is not post');
        }
        /* process form-data */
        $locationids = Request::post('locations', [], "ARRAY");

        /* global room has id 0 */
        if(empty($locationids)) {
            $locationids[] = 0;
        }

        $examid = Request::post('examid', 0, 'int');
        $starttime   = strtotime(Request::post('starttime_date') . " " . Request::post('starttime_time'));
        $endtime     = strtotime(Request::post('endtime_date') . " " . Request::post('endtime_time'));
        $description = Request::post('description');
        if (!$this->dateSane($starttime)) {
            Message::addError('starttime-invalid', Request::post('starttime_date') . " " . Request::post('starttime_time'));
            Util::redirect('?do=exams');
        }
        if (!$this->dateSane($endtime)) {
            Message::addError('endtime-invalid', Request::post('endtime_date') . " " . Request::post('endtime_time'));
            Util::redirect('?do=exams');
        }
        if ($endtime <= $starttime) {
            Message::addError('end-before-start');
            Util::redirect('?do=exams');
        }

        if ($examid === 0) {
            // No examid given, is add
            $res = Database::exec("INSERT INTO exams(starttime, endtime, description) VALUES(:starttime, :endtime, :description);",
                  compact('starttime', 'endtime', 'description')) !== false;

            $exam_id = Database::lastInsertId();
            foreach ($locationids as $lid) {
                $res = $res && Database::exec("INSERT INTO exams_x_location(examid, locationid) VALUES(:exam_id, :lid)", compact('exam_id', 'lid')) !== false;
            }
            if ($res === false) {
                Message::addError('exam-not-added');
            }  else {
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
        $res = Database::exec("UPDATE exams SET starttime = :starttime, endtime = :endtime, description = :description WHERE examid = :examid",
              compact('starttime', 'endtime', 'description', 'examid')) !== false;
        /* drop all connections and reconnect to rooms */
        $res = $res && Database::exec("DELETE FROM exams_x_location WHERE examid = :examid", compact('examid')) !== false;
        /* reconnect */
        foreach ($locationids as $lid) {
            $res = $res && Database::exec("INSERT INTO exams_x_location(examid, locationid) VALUES(:examid, :lid)", compact('examid', 'lid')) !== false;
        }
        if ($res !== FALSE) {
            Message::addInfo("changes-successfully-saved");
        } else {
            Message::addError("error-while-saving-changes");
        }
        Util::redirect('?do=exams');
    }

    protected function doPreprocess()
    {
        User::load();

        $req_action = Request::any('action', 'show');
        if (in_array($req_action, ['show', 'add', 'delete', 'edit', 'save'])) {
            $this->action = $req_action;
        }

        if ($this->action === 'show') {

            $this->readExams();
            $this->readLocations();
            $this->readLectures();

        } elseif ($this->action === 'edit') {

            $examid = Request::get('examid', 0, 'int');
            $this->currentExam = Database::queryFirst("SELECT * FROM exams WHERE examid = :examid", array('examid' => $examid));
            if ($this->currentExam === false) {
                Message::addError('invalid-exam-id', $examid);
                Util::redirect('?do=exams');
            }
            $this->readLocations($examid);

        } elseif ($this->action === 'save') {

            $this->saveExam();

        } elseif ($this->action === 'delete') {

            if (!Request::isPost()) { die('delete only works with a post request'); }
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
            Render::setTitle("All Exams");
            Render::addTemplate('page-exams',
                [ 'exams'        => $this->makeExamsForTemplate(),
                  'exams_json'   => $this->makeItemsForVis(),
                  'rooms_json'   => $this->makeGroupsForVis(),
                  'vis_begin'    => strtotime('-5 minute') * 1000,
                  'vis_end'      => strtotime('+2 day') * 1000,
                  'vis_min_date' => strtotime('-1 day') * 1000,
                  'vis_max_date' => strtotime('+3 month') * 1000
                ]);
        } elseif ($this->action === "add") {
            Render::setTitle(Dictionary::translate('title_add-exam'));
            $this->readLocations();
            Render::addTemplate('page-add-edit-exam', ['locations' => $this->locations]);
        } elseif ($this->action === 'edit') {
            Render::setTitle(Dictionary::translate('title_edit-exam'));
            $exam = [
                'examid'    => $this->currentExam['examid'],
                'starttime_date' => date('Y-m-d', $this->currentExam['starttime']),
                'starttime_time' => date('H:i',   $this->currentExam['starttime']),
                'endtime_date' => date('Y-m-d', $this->currentExam['endtime']),
                'endtime_time' => date('H:i',   $this->currentExam['endtime']),
                'description' => $this->currentExam['description']
            ];
            Render::addTemplate('page-add-edit-exam', ['exam' => $exam, 'locations' => $this->locations]);
        }
    }

}
