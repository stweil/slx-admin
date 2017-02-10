<?php

function fetchNewTimeTable($locationID){
            //Get room information
            $dbquery1 = Database::simpleQuery("SELECT serverid, serverroomid, lastcalenderupdate FROM location_info WHERE locationid = :id", array('id' => $locationID));
            $dbd1=$dbquery1->fetch(PDO::FETCH_ASSOC);
            $serverID = $dbd1['serverid'];
            $roomID = $dbd1['serverroomid'];
            $lastUpdate = $dbd1['lastcalenderupdate'];
            //returns the cached version if it is new enough
            if(strtotime($lastUpdate) > strtotime("-30 minutes")) {
                $dbquery3 = Database::simpleQuery("SELECT calendar FROM location_info WHERE locationid = :id", array('id' => $locationID));
                $dbd3=$dbquery3->fetch(PDO::FETCH_ASSOC);
                return $dbd3['callendar'];
            }
            //Get login data for the server
            $dbquery2 = Database::simpleQuery("SELECT serverurl, servertype, login, passwd FROM `setting_location_info` WHERE serverid = :id", array('id' => $serverID));
            $dbd2=$dbquery2->fetch(PDO::FETCH_ASSOC);
            $url = $dbd2['serverurl'];
            $type = $dbd2['servetype'];
            $lname = $dbd2['login'];
            $passwd = $dbd2['passwd'];
            //Return json with dates
            if($type == 'HISinOne'){
                $ttable = HisInOneRequest($url,$roomID,$lname,$passwd);
                $now = strtotime('Now');
                $dbquery1 = Database::simpleQuery("UPDATE location_info SET calendar = :ttable, lastcalenderupdate = :now WHERE locationid = :id ", array('id' => $locationID,'ttable' => $ttable,'now'=> $now));
            }
            else{
                $ttable = "{}";
            }
             return $ttable;         
        }

function HisInOneRequest($url,$roomID,$lname,$passwd){
    $url = $url."/qisserver/services2/CourseService";
    $client = new HisInOneSoapClient($url, $lname, $passwd);
    return $client->giveBackJson($roomID);
    

}