<?php
class Coursebackend_Dummy extends CourseBackend {
    private $pw;

    public function setCredentials($json,$location,$serverID) {
      $x = $json;
      $this->pw = $x['password'];

      if ($this->pw == "mfg") {
        $this->error = false;
        return true;
      } else {
        $this->errormsg = "USE mfg as password!";
        $this->error = true;
        return false;
      }
    }

    public function checkConnection(){
      if ($this->pw == "mfg") {
        $this->error = false;
        return true;
      } else {
        $this->errormsg = "USE mfg as password!";
        $this->error = true;
        return false;
      }
    }

    public function getCredentials(){
      $options = ["opt1", "opt2", "opt3", "opt4", "opt5", "opt6", "opt7", "opt8"];
      $credentials = ["username" => "string","password"=>"password","integer"=>"int","option"=>$options,"CheckTheBox" =>"bool","CB2 t" =>"bool"];
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
