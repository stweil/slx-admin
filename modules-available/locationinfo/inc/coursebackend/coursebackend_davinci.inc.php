<?php
class Coursebackend_Davinci extends CourseBackend {

    private $location;
    public $serverID;
    
    function __construct($location, $serverID) {
        $this->location = $location."/daVinciIS.dll?";
        $this->serverID = $serverID;
    }
    public function setCredentials($param) {
        //Davinci doesn't have credentials
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
