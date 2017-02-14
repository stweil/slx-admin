<?php
interface iTimetableRequest
{
    public function getJson($param);
    public function getJsons($param);
}

class HisInOneSoapClient implements iTimetableRequest
{
    private $username;
    private $password;
    private $location;


    //Constructs the HisInOneClient all parameters are strings
    function __construct($location, $username, $password) {
        $this->location = $location;
        $this->password = $password;
        $this->username = $username;
    }

    
    //Contstructs the Soap Header $doc is a DOMDocument this returns a DOMElement
    private function getHeader($doc){
        $header = $doc->createElement( 'SOAP-ENV:Header');
        $security = $doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd','ns2:Security');
        $mustunderstand = $doc->createAttribute('SOAP-ENV:mustUnderstand');
        $mustunderstand->value = 1;
        $security->appendChild($mustunderstand);
        $header->appendChild($security);
        $token = $doc->createElement('ns2:UsernameToken');
        $security->appendChild($token);
        $user = $doc->createElement('ns2:Username',  $this->username);
        $token->appendChild($user);
        $pass = $doc->createElement('ns2:Password', $this->password);
        $type = $doc->createAttribute('Type');
        $type ->value = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';
        $pass ->appendChild($type);
        $token->appendChild($pass);
        return $header;
    }
    
    //returns the IDs in an array for a given roomID
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
        $doc  = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        $envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'SOAP-ENV:Envelope');
        $doc->appendChild($envelope);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:ns1', 'http://www.his.de/ws/courseService');
        
        $header = $this->getHeader($doc);
        $envelope->appendChild($header);
        //Body of the request
        $body = $doc->createElement('SOAP-ENV:Body');
        $envelope->appendChild($body);
        $findUnit= $doc->createElement('ns1:findUnit');
        $body->appendChild($findUnit);
        $unitDefaulttext = $doc->createElement('ns1:unitDefaulttext');
        $findUnit->appendChild($unitDefaulttext);
        $unitElementnr = $doc->createElement('ns1:unitElementnr');
        $findUnit->appendChild($unitElementnr);
        $editingStatusId = $doc->createElement('ns1:editingStatusId');
        $findUnit->appendChild($editingStatusId);
        $courseEventtypeId = $doc->createElement('ns1:courseEventtypeId');
        $findUnit->appendChild($courseEventtypeId);
        $courseTeachingLanguageId = $doc->createElement('ns1:courseTeachingLanguageId');
        $findUnit->appendChild($courseTeachingLanguageId);
        $planelementDefaulttext = $doc->createElement('ns1:planelementDefaulttext');
        $findUnit->appendChild($planelementDefaulttext);
        $parallelgroupId= $doc->createElement('ns1:parallelgroupId');
        $findUnit->appendChild($parallelgroupId);
        $termYearN = $doc->createElement('termYear',$termyear);
        $findUnit->appendChild($termYearN);
        $termTypeValueId = $doc->createElement('termTypeValueId',$termtype);
        $findUnit->appendChild($termTypeValueId);
        $individualDatesExecutionDate = $doc->createElement('ns1:individualDatesExecutionDate');
        $findUnit->appendChild($individualDatesExecutionDate);
        $individualDatesStarttime = $doc->createElement('ns1:individualDatesStarttime');
        $findUnit->appendChild($individualDatesStarttime);
        $individualDatesEndtime = $doc->createElement('ns1:individualDatesEndtime');
        $findUnit->appendChild($individualDatesEndtime);
        $roomIdN = $doc->createElement('ns1:roomId',$roomID);
        $findUnit->appendChild($roomIdN);
        
        $soap_request = $doc->saveXML();
        $respons1 = $this->__doRequest($soap_request, "findUnit");
        $respons2 = $this->toArray($respons1);
        $id = $respons2['soapenvBody']['hisfindUnitResponse']['hisunitIds']['hisid'];
        return $id;
    }
    
    //This function sends a Soaprequest with the eventID and returns an array which contains much
    // informations, like start and enddates for events and their name.
    public function readUnit($unit) {
        $doc  = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        $envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'SOAP-ENV:Envelope');
        $doc->appendChild($envelope);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:ns1', 'http://www.his.de/ws/courseService');
        
        $header = $this->getHeader($doc);
        $envelope->appendChild($header);
        //body of the request
        $body = $doc->createElement('SOAP-ENV:Body');
        $envelope->appendChild($body);
        $readUnit =$doc->createElement('ns1:readUnit');
        $body->appendChild($readUnit);
        $unitId = $doc->createElement('ns1:unitId',$unit);
        $readUnit->appendChild($unitId);

        $soap_request = $doc->saveXML();
        $respons1 = $this->__doRequest($soap_request, "readUnit");
        $respons2 = $this->toArray($respons1);
        $respons3 = $respons2['soapenvBody']['hisreadUnitResponse'];
        return $respons3;
    }
    //Makes a SOAP-Request as a normal POST
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
    
    //this function transforms a xml string into an array
    private function toArray($response){
        $cleanresponse = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
        $xml = new SimpleXMLElement($cleanresponse);
        $array = json_decode(json_encode((array)$xml), TRUE);
        return $array;
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
        $DateArray = array();
        $startdate = strtotime('Now');
        for($i=0 ;$i<=7; $i++) {
            $DateArray[] = date('Y-m-d', strtotime("+ {$i} day", $startdate));
        
        }
        return $DateArray;
    }

}
?>
