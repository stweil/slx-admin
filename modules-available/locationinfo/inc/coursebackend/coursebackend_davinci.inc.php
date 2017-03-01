<?php
class Coursebackend_Davinci extends CourseBackend {

    private $location;
    public $serverID;
    
    function __construct($location, $serverID) {
        
    }
    public function setCredentials($json,$location,$serverID) {
        $this->error = true;
        $this->errormsg = "This class is just a place holder";
        $this->location = $location."/daVinciIS.dll?";
        $this->serverID = $serverID;
        //Davinci doesn't have credentials
    }
    
    public function checkConection(){
        $this->fetchSchedulesInternal(42);
        return $this->error;
    }
    public function getCredentials(){
        $return = array();
        return $return;
    }
    public function getDisplayName(){
        return'Davinci';
    }
    public function getCacheTime(){
        return 0;
    }
    public function getRefreshTime(){
        return 0;
    }
    public function fetchSchedulesInternal($roomId){
        
    }

}
?>
