<?php

class Page_Exams extends Page
{
    var $action;
    var $exams;
    var $locations;
    var $lectures;


    protected function readLocations()
    {
        $tmp = Database::simpleQuery("select * from location;", []);
        while ($loc = $tmp->fetch(PDO::FETCH_ASSOC)) {
            $this->locations[] = $loc;
        }
    }

    protected function readExams()
    {
        $tmp = Database::simpleQuery("select examid, starttime, endtime, GROUP_CONCAT(locationid) AS locationids,"
            . " GROUP_CONCAT(locationname) AS locationnames from "
            . "exams NATURAL JOIN exams_x_locations NATURAL JOIN location GROUP BY examid;", []);
        while ($exam = $tmp->fetch(PDO::FETCH_ASSOC)) {
            $this->exams[] = $exam;
        }
    }

    protected function readLectures()
    {
        $tmp = Database::simpleQuery("select * from sat.lecture NATURAL JOIN sat.lecture_x_location");
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
        foreach ($this->exams as $e) {
            foreach(explode(',', $e['locationids']) as $locationid) {
                $out[] = [ 'id'         => 'shadow_' . $unique_ids++,
                           'content'    => '',
                           'start'      => $e['starttime'],
                           'end'        => $e['endtime'],
                           'type'       => 'background',
                           'group'      => $locationid,
                       ];
                }
        }
        /* add the lectures */
        $i = 2;
        foreach ($this->lectures as $l) {
            $out[] = [
                'id'        => $l['lectureid'],
                'content'   => $l['displayname'],
                'start'     => date(DATE_ISO8601, $l['starttime']),
                'end'       => date(DATE_ISO8601, $l['endtime']),
                'group'     => $l['locationid'],
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

    protected function doPreprocess()
    {
        User::load();

        $req_action = Request::get('action', 'show');
        if (in_array($req_action, ['show', 'add', 'delete'])) {
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

                $starttime  = Request::post('starttime_date') . " " . Request::post('starttime_time');
                $endtime    = Request::post('endtime_date') . " " . Request::post('endtime_time');
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
        } else {
            Util::traceError("unknown action");
        }
    }

    protected function doRender()
    {
        // Render::setTitle(Dictionary::translate('lang_exams'));
        //Render::addTemplate('page-exams', $_POST);

        if ($this->action === "show") {
            Render::setTitle("All Exams");
            Render::addTemplate('page-exams',
                [ 'exams' => $this->exams,
                  'exams_json' => $this->makeItemsForVis(),
                  'rooms_json' => $this->makeGroupsForVis(),
                  'vis_begin' => date('Y-m-d'),
                  'vis_end' => date('Y-m-d', strtotime("+2 day"))
                ]);
        } elseif ($this->action === "add") {
            Render::setTitle("Add Exam");
            Render::addTemplate('page-add-exam', ['locations' => $this->locations]);
        }
        // Render::output('hi');
    }

}
