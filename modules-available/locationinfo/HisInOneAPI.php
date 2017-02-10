<?php
interface iTimetableRequest
{
    public function getJson($param);
    public function getJsonsl($param);
}

class HisInOneSoapClient implements iTimetableRequest
{
    private $username;
    private $password;
    private $location;
    private $header;


    function __construct($location, $username, $password) {
        $this->location = $location;
        $this->password = $password;
        $this->username = $username;
        $this->header = '<SOAP-ENV:Envelope xmlns:ns1="http://www.his.de/ws/courseService" xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
                . '<SOAP-ENV:Header><ns2:Security SOAP-ENV:mustUnderstand="1" xmlns:ns2="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
                . '<ns2:UsernameToken><ns2:Username>'.$this->username .'</ns2:Username>'
                . '<ns2:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">'.$this->password.'</ns2:Password>'
                . '</ns2:UsernameToken></ns2:Security></SOAP-ENV:Header>';
    }
    //returns a xml with information of one lecture given as int parameter
    public function getPlanelementForExportById($param)
    {
        $soap_request = $this->header.'<SOAP-ENV:Body>'
                . '<ns1:getPlanelementForExportById><ns1:planelementId>'.$param.'</ns1:planelementId>'
                . '</ns1:getPlanelementForExportById></SOAP-ENV:Body></SOAP-ENV:Envelope>';
        return $this->__doRequest($soap_request,"getPlanelementForExportByID");
        
    }
    public function findUnit($roomID){
        $termyear = date('Y');
        $termtype = date('n');
        if($termtype > 3 && termtype < 10){
            $termtype = 2;
        }
        elseif ($termtype > 10) {
        $termtype = 1;
        $termyear = $termyear + 1;
        }
        else{
            $termtype = 1;
        }
        
        $soap_request = $this->header.'<SOAP-ENV:Body><ns1:findUnit><ns1:unitDefaulttext/><ns1:unitElementnr/>'
                . '<ns1:editingStatusId/><ns1:courseEventtypeId/><ns1:courseTeachingLanguageId/>'
                . '<ns1:planelementDefaulttext/><ns1:parallelgroupId/>'
                . '<ns1:termYear>'.$termyear.'</ns1:termYear><ns1:termTypeValueId>'.$termtype.'</ns1:termTypeValueId>'
                . '<ns1:individualDatesExecutionDate/><ns1:individualDatesStarttime/><ns1:individualDatesEndtime/>'
                . '<ns1:roomId>'.$roomID.'</ns1:roomId></ns1:findUnit></SOAP-ENV:Body></SOAP-ENV:Envelope>';
        $respons1 = $this->__doRequest($soap_request, "findUnit");
        $respons2 = $this->toArray($respons1);
        $id = $respons2['soapenvBody']['hisfindUnitResponse']['hisunitIds']['hisid'];
        return $id;
    }
    
    public function readUnit($unit) {
        $soap_request = $this->header.'<SOAP-ENV:Body><ns1:readUnit>'
                . '<ns1:unitId>'.$unit.'</ns1:unitId></ns1:readUnit>'
                . '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
         $respons1 = $this->__doRequest($soap_request, "readUnit");
         $respons2 = $this->toArray($respons1);
         $id = $respons2['soapenvBody']['hisreadUnitResponse'];
         return $id;
    }
    //Makes a SOAP-Request
    function __doRequest($request, $action){
        $header = array(
            "Content-type: text/xml;charset=\"utf-8\"",
            "SOAPAction: \"".$action."\"",
            "Content-length: ".strlen($request),
            );

        $soap_do = curl_init();

        $options = array( 
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_URL            => $this->location ,			
            CURLOPT_POSTFIELDS => $request ,
            CURLOPT_HTTPHEADER => $header ,
            );

        curl_setopt_array($soap_do , $options);

        $output = curl_exec($soap_do);

        if( $output === false) 
        {
                $err = 'Curl error: ' . curl_error($soap_do);
                echo $err;
            } 
        else 
        {
            ///Operation completed successfully
            }
        curl_close($soap_do);
        return $output;
    }
    
    //Request for a timetable with roomid as int
    public function getJson($param){
        //get all eventIDs in a given room
        $eventIDs = $this-> findUnit($param);
        //get all information on each event
        foreach ($eventIDs as $each_event) {
            $events[] = $this->readUnit((int) $each_event);
        }
        $timetable = array();
        $currentWeek = $this->getCurrentWeekDates();
        //Here I go over the soapresponse
        foreach ($events as $event){
            $title = $event['hisunit']['hisplanelements']['hisplanelement'][0]['hisdefaulttext'];
            foreach($event as $subject){
                $units = $subject['hisplanelements']['hisplanelement'];
                foreach($units as $unit){
                    $dates = $unit['hisplannedDates']['hisplannedDate']['hisindividualDates']['hisindividualDate'];
                    foreach($dates as $date){
                        $roomID = $date['hisroomId'];
                        $datum = $date['hisexecutiondate'];
                        if(intval($roomID) == $param && in_array($datum,$currentWeek)){
                            
                            $startTime = $date['hisstarttime'];
                            $endTime = $date['hisendtime'];
                            $json = array(
                                'title' => $title,
                                'start' => $datum." ".$startTime,
                                'end'   => $datum." ".$endTime
                            );
                            array_push($timetable,$json);
                        }
                        
                       
                      
                       
                   }
               }
           }
           
       }
        return json_encode($timetable);
    }
    //Request for a timetable with roomids as array
    public function getJsons($param){
        //get all eventIDs in a given room
        foreach ($param as $ID) {
            $eventIDs = $this-> findUnit($ID);
        }
        $eventIDs = array_unique($eventIDs);
        
        //get all information on each event
        foreach ($eventIDs as $each_event) {
            $events[] = $this->readUnit((int) $each_event);
        }
        $ttables = [];
        $currentWeek = $this->getCurrentWeekDates();
        foreach ($param as $room){
            $timetable = array();
            //Here I go over the soapresponse
            foreach ($events as $event){
                $title = $event['hisunit']['hisplanelements']['hisplanelement'][0]['hisdefaulttext'];
                foreach($event as $subject){
                    $units = $subject['hisplanelements']['hisplanelement'];
                    foreach($units as $unit){
                        $dates = $unit['hisplannedDates']['hisplannedDate']['hisindividualDates']['hisindividualDate'];
                        foreach($dates as $date){
                            $roomID = $date['hisroomId'];
                            $datum = $date['hisexecutiondate'];
                            if(intval($roomID) == $room && in_array($datum,$currentWeek)){
                            
                                $startTime = $date['hisstarttime'];
                                $endTime = $date['hisendtime'];
                                $json = array(
                                    'title' => $title,
                                    'start' => $datum." ".$startTime,
                                    'end'   => $datum." ".$endTime
                                );
                                $timetable[]= $json;
                            }
                        }
                    }
                } 
            }
            $ttables[$room] =json_encode($timetable);
        }
        return $ttables;
    }
    function getCurrentWeekDates()
    {
        $startdate = date('Y-m-d');
        $enddate = date('+1 week');
        

        $DateArray = array();
        $timestamp = strtotime($startdate);
        while ($startdate <= $enddate) {
            $startdate = date('Y-m-d', $timestamp);
            $DateArray[] = $startdate;
            $timestamp = strtotime('+1 days', strtotime($startdate));
        }
        return $DateArray;
    }

}
?>
