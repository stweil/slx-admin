<?php

class Exams {


    /**
     * @param: array of location ids
     * @return: true iff for any of the given location ids an exam is scheduled
     **/
    public static function isInExamMode($locationIds) {
        // TODO: Better use prepared statement
        $l = '(' . implode(', ', $locationIds) . ')';
        $res = Database::queryFirst("SELECT (COUNT(examid) > 0) as examMode FROM exams WHERE starttime < NOW() AND endtime > NOW() AND locationid IN $l", []);

        return $res['examMode'];
    }
}
