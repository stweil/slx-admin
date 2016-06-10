<?php

class Page_Exams extends Page
{
    var $action;
    var $exams;
    var $locations;

    protected function doPreprocess()
    {
        User::load();

        $req_action = Request::get('action', 'show');
        if (in_array($req_action, ['show', 'add', 'delete'])) {
            $this->action = $req_action;
        }

        if ($this->action === 'show') {
            $tmp = Database::simpleQuery("select * from exams NATURAL LEFT OUTER JOIN location;", []);
            while ($exam = $tmp->fetch(PDO::FETCH_ASSOC)) {
                $this->exams[] = $exam;
            }
        } elseif ($this->action === 'add') {
            $tmp = Database::simpleQuery("select * from location;", []);
            while ($loc = $tmp->fetch(PDO::FETCH_ASSOC)) {
                $this->locations[] = $loc;
            }

            if (Request::isPost()) {
                /* process form-data */
                $locationid = Request::post('location');
                $starttime  = Request::post('starttime');
                $endtime    = Request::post('endtime');

                $res = Database::exec("INSERT INTO exams(locationid, starttime, endtime) VALUES(:locationid, :starttime, :endtime);",
                    compact('locationid', 'starttime', 'endtime'));

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
            Render::addTemplate('page-exams', ['exams' => $this->exams]);
        } elseif ($this->action === "add") {
            Render::setTitle("Add Exam");
            Render::addTemplate('page-add-exam', ['locations' => $this->locations]);
        }
        // Render::output('hi');
    }

}
