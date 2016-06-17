<?php

class Page_Exams extends Page
{
    var $action;
    var $exams;
    var $locations;
    var $lectures;


    /** if examid is set, also add a column 'selected' **/
    protected function readLocations($examid = NULL)
    {
        if ($examid == NULL) {
            $tmp = Database::simpleQuery("SELECT locationid, locationname FROM location;", []);
        } else {
            $tmp = Database::simpleQuery("SELECT locationid, locationname, " .
                "EXISTS(SELECT * FROM exams NATURAL JOIN exams_x_locations WHERE locationid = x.locationid AND examid= :examid) AS selected FROM location x", compact('examid'));
        }
        while ($loc = $tmp->fetch(PDO::FETCH_ASSOC)) {
            $this->locations[] = $loc;
        }
    }

    protected function readExams()
    {
        $tmp = Database::simpleQuery("select examid, starttime, endtime, description, GROUP_CONCAT(locationid) AS locationids, "
            . "GROUP_CONCAT(locationname) AS locationnames FROM "
            . "exams NATURAL LEFT JOIN exams_x_locations NATURAL LEFT JOIN location GROUP BY examid", []);
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
                'id'        => $l['lectureid'],
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
    protected function doPreprocess()
    {
        User::load();

        $req_action = Request::get('action', 'show');
        if (in_array($req_action, ['show', 'add', 'delete', 'edit'])) {
            $this->action = $req_action;
        }

        if ($this->action === 'show') {
            $this->readExams();
            $this->readLocations();
            $this->readLectures();
        } elseif ($this->action === 'add') {
            $this->readLocations();
            if (Request::isPost()) {
                /* process form-data */
                $locationids = Request::post('locations', [], "ARRAY");

                /* global room has id 0 */
                if(empty($locationids)) {
                    $locationids[] = 0;
                }

                $starttime   = strtotime(Request::post('starttime_date') . " " . Request::post('starttime_time'));
                $endtime     = strtotime(Request::post('endtime_date') . " " . Request::post('endtime_time'));
                $description = Request::post('description');

                $res = Database::exec("INSERT INTO exams(starttime, endtime, description) VALUES(:starttime, :endtime, :description);",
                    compact('starttime', 'endtime', 'description'));

                $exam_id = Database::lastInsertId();
                foreach ($locationids as $lid) {
                    $res = $res && Database::exec("INSERT INTO exams_x_locations(examid, locationid) VALUES(:exam_id, :lid)", compact('exam_id', 'lid'));
                }


                if ($res === false) {
                    Message::addError('exam-not-added');
                }  else {
                    Message::addInfo('exam-added-success');
                }
                Util::redirect('?do=exams');
            }

        } elseif ($this->action === 'delete') {
            if (!Request::isPost()) { die('delete only works with a post request'); }
            $examid = Request::post('examid');
            $res = Database::exec("DELETE FROM exams WHERE examid = :examid;", compact('examid'));
            $res = Database::exec("DELETE FROM exams_x_locations WHERE examid = :examid;", compact('examid'));
            if ($res === false) {
                Message::addError('exam-not-deleted-error');
            } else {
                Message::addInfo('exam-deleted-success');
            }
            Util::redirect('?do=exams');
        } elseif ($this->action === 'edit') {
            $examid = Request::get('examid', -1, 'int');
            $this->readLocations($examid);
            $this->currentExam = Database::queryFirst("SELECT * FROM exams WHERE examid = :examid", array('examid' => $examid));

            if (Request::isPost()) {
                $locationids = Request::post('locations', [], "ARRAY");

                /* global room has id 0 */
                if(empty($locationids)) {
                    $locationids[] = 0;
                }

                $starttime   = strtotime(Request::post('starttime_date') . " " . Request::post('starttime_time'));
                $endtime     = strtotime(Request::post('endtime_date') . " " . Request::post('endtime_time'));
                $description = Request::post('description');
                /* update fields */
                $res = Database::exec("UPDATE exams SET starttime = :starttime, endtime = :endtime, description = :description WHERE examid = :examid",
                    compact('starttime', 'endtime', 'description', 'examid'));
                /* drop all connections and reconnect to rooms */
                $res = $res !== FALSE && Database::exec("DELETE FROM exams_x_locations WHERE examid = :examid", compact('examid'));
                /* reconnect */
                foreach ($locationids as $lid) {
                    $res = $res !== FALSE && Database::exec("INSERT INTO exams_x_locations(examid, locationid) VALUES(:examid, :lid)", compact('examid', 'lid'));
                }
                if ($res !== FALSE) {
                    Message::addInfo("changes-successfully-saved");
                } else {
                    Message::addError("error-while-saving-changes");
                }
                Util::redirect('?do=exams');
            }

        } else {
            Util::traceError("action not implemented");
        }
    }

    protected function doRender()
    {
        // Render::setTitle(Dictionary::translate('lang_exams'));
        //Render::addTemplate('page-exams', $_POST);

        if ($this->action === "show") {
            Render::setTitle("All Exams");
            Render::addTemplate('page-exams',
                [ 'exams'        => $this->makeExamsForTemplate(),
                  'exams_json'   => $this->makeItemsForVis(),
                  'rooms_json'   => $this->makeGroupsForVis(),
                  'vis_begin'    => time() * 1000,
                  'vis_end'      => strtotime('+2 day') * 1000,
                  'vis_min_date' => strtotime('-1 day') * 1000,
                  'vis_max_date' => strtotime('+3 month') * 1000
                ]);
        } elseif ($this->action === "add") {
            Render::setTitle("Add Exam");
            Render::addTemplate('page-add-exam', ['locations' => $this->locations]);
        } elseif ($this->action === 'edit') {
            Render::setTitle("Edit Exam");
            $exam = [
                'examid'    => $this->currentExam['examid'],
                'starttime_date' => date('Y-m-d', $this->currentExam['starttime']),
                'starttime_time' => date('H:i',   $this->currentExam['starttime']),
                'endtime_date' => date('Y-m-d', $this->currentExam['endtime']),
                'endtime_time' => date('H:i',   $this->currentExam['endtime']),
                'description' => $this->currentExam['description']
            ];
            Render::addTemplate('page-edit-exam', ['exam' => $exam, 'locations' => $this->locations]);
        }
        // Render::output('hi');
    }

}
