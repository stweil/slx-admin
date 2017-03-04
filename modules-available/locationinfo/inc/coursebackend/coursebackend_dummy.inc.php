<?php
class Coursebackend_Dummy extends CourseBackend {

    private $location;
    public $serverID;

    function __construct($location, $serverID) {

    }

    public function setCredentials($json,$location,$serverID) {
    }

    public function checkConnection(){
        return $true;
    }

    public function getCredentials(){
      $options = ["opt1", "opt2", "opt3", "opt4", "opt5", "opt6", "opt7", "opt8"];
      $credentials = ["username" => "string","password"=>"string","option"=>$options];
      return $credentials;
    }

    public function getDisplayName(){
        return'Dummy with array';
    }

    public function getCacheTime(){
        return 0;
    }

    public function getRefreshTime(){
        return 0;
    }

    public function fetchSchedulesInternal($roomId){
      $a = array();
      foreach ($roomId as $id) {
        $x['id'] = $id;
        $calendar['title'] = "test exam";
        $calendar['start'] = "2017-3-08 13:00:00";
        $calendar['end'] = "2017-3-08 16:00:00";
        $calarray = array();
        $calarray[] = $calendar;
        $x['calendar'] = $calarray;
        $a[$id] = $calarray;
      }


      return $a;
    }

}
?>
