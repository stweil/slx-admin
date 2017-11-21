<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Enumeration\DefaultShapeNamesType;
use jamesiarmes\PhpEws\Enumeration\DistinguishedFolderIdNameType;
use jamesiarmes\PhpEws\Enumeration\ItemQueryTraversalType;
use jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use jamesiarmes\PhpEws\Request\FindItemType;
use jamesiarmes\PhpEws\Request\ResolveNamesType;
use jamesiarmes\PhpEws\Type\CalendarViewType;
use jamesiarmes\PhpEws\Type\DistinguishedFolderIdType;
use jamesiarmes\PhpEws\Type\EmailAddressType;
use jamesiarmes\PhpEws\Type\ItemResponseShapeType;

class CourseBackend_Exchange extends CourseBackend {

    private $username = '';
    private $password = '';
    private $baseUrl;
    private $client_version;
    private $timezone = 'W. Europe Standard Time';  // TODO: make this configurable some time
    private $verifyHostname = true;
    private $verifyCert = true;

    /**
     * @return string return display name of backend
     */
    public function getDisplayName() {
        return "Microsoft Exchange";
    }

    /**
     * @returns \BackendProperty[] list of properties that need to be set
     */
    public function getCredentialDefinitions() {
        $options = [Client::VERSION_2007, Client::VERSION_2007_SP1, Client::VERSION_2009, Client::VERSION_2010,
            Client::VERSION_2010_SP1, Client::VERSION_2010_SP2, Client::VERSION_2013, Client::VERSION_2013_SP1, Client::VERSION_2016];
        return [
            new BackendProperty('baseUrl', 'string'),
            new BackendProperty('username', 'string'),
            new BackendProperty('password', 'password'),
            new BackendProperty('client_version', $options),
            new BackendProperty('verifyCert', 'bool', true),
            new BackendProperty('verifyHostname', 'bool', true)
        ];
    }

    /**
     * @return boolean true if the connection works, false otherwise
     */
    public function checkConnection() {
        $client = $this->getClient();
        $request = new ResolveNamesType();
        $request->UnresolvedEntry = $this->username;
        $request->ReturnFullContactData = false;

        try {
            $response = $client->ResolveNames($request);
        } catch (Exception $e) {
            error_log("There was an error");
            error_log($e->getMessage());
            return false;
        }

        if ($response->ResponseMessages->ResolveNamesResponseMessage[0]->ResponseCode == "NoError") {
            $mailadress = $response->ResponseMessages->ResolveNamesResponseMessage[0]->ResolutionSet->Resolution[0]->Mailbox->EmailAddress;
            return !empty($mailadress);
        }
        return false;
    }

    /**
     * uses json to setCredentials, the json must follow the form given in
     * getCredentials
     *
     * @param array $data assoc array with data required by backend
     * @returns bool if the credentials were in the correct format
     */
    public function setCredentialsInternal($data) {
        foreach (['username', 'password'] as $field) {
            if (empty($data[$field])) {
                $this->error = 'setCredentials: Missing field ' . $field;
                return false;
            }
        }

        if (empty($data['baseUrl'])) {
            $this->error = "No url is given";
            return false;
        }

        $this->username = $data['username'];
        $this->password = $data['password'];

        $this->baseUrl = $data['baseUrl'];
        $this->client_version = $data['client_version'];

        $this->verifyHostname = $data['verifyHostname'];
        $this->verifyCert = $data['verifyCert'];

        return true;
    }

    /**
     * @return int desired caching time of results, in seconds. 0 = no caching
     */
    public function getCacheTime() {
        return 0;
    }

    /**
     * @return int age after which timetables are no longer refreshed should be
     * greater then CacheTime
     */
    public function getRefreshTime() {
        return 0;
    }

    /**
     * Internal version of fetch, to be overridden by subclasses.
     *
     * @param $roomIds array with local ID as key and serverId as value
     * @return array a recursive array that uses the roomID as key
     * and has the schedule array as value. A shedule array contains an array in this format:
     * ["start"=>'JJJJ-MM-DD HH:MM:SS',"end"=>'JJJJ-MM-DD HH:MM:SS',"title"=>string]
     */
    protected function fetchSchedulesInternal($requestedRoomIds) {
        $startDate = new DateTime('today 0:00');
        $endDate = new DateTime('+7 days 0:00');
        $client = $this->getClient();

        $schedules = [];
        foreach ($requestedRoomIds as $roomId) {
            $items = $this->findEventsForRoom($client, $startDate, $endDate, $roomId);

            // Iterate over the events that were found, printing some data for each.
            foreach ($items as $item) {
                $start = new DateTime($item->Start);
                $end = new DateTime($item->End);

                $schedules[$roomId][] = array(
                    'title' => $item->Subject,
                    'start' => $start->format('Y-m-d') . "T" . $start->format('G:i:s'),
                    'end' => $end->format('Y-m-d') . "T" . $end->format('G:i:s')
                );
            }
        }
        return $schedules;
    }

    public function findEventsForRoom($client, $start_date, $end_date, $email_room) {
        $request = new FindItemType();
        $request->Traversal = ItemQueryTraversalType::SHALLOW;
        $request->ItemShape = new ItemResponseShapeType();
        $request->ItemShape->BaseShape = DefaultShapeNamesType::ALL_PROPERTIES;

        $request->CalendarView = new CalendarViewType();
        $request->CalendarView->StartDate = $start_date->format('c');
        $request->CalendarView->EndDate = $end_date->format('c');
        $folder_id = new DistinguishedFolderIdType();
        $folder_id->Id = DistinguishedFolderIdNameType::CALENDAR;
        $folder_id->Mailbox = new EmailAddressType();
        $folder_id->Mailbox->EmailAddress = $email_room;
        $request->ParentFolderIds->DistinguishedFolderId[] = $folder_id;
        $response = $client->FindItem($request);
        $response_messages = $response->ResponseMessages->FindItemResponseMessage;

        $items = [];
        foreach ($response_messages as $response_message) {
            // Make sure the request succeeded.
            if ($response_message->ResponseClass != ResponseClassType::SUCCESS) {
                $code = $response_message->ResponseCode;
                $message = $response_message->MessageText;
                error_log("Failed to search for events with \"$code: $message\"\n");
                continue;
            }
            $items = $response_message->RootFolder->Items->CalendarItem;
        }
        return $items;
    }

    public function getClient() {
        $client = new Client($this->baseUrl, $this->username, $this->password, $this->client_version);
        $client->setTimezone($this->timezone);
        $client->setCurlOptions(array(
            CURLOPT_SSL_VERIFYPEER => $this->verifyHostname,
            CURLOPT_SSL_VERIFYHOST => $this->verifyCert
        ));

        return $client;
    }

    function var_error_log($object = null) {
        ob_start(); // start buffer capture
        var_dump($object); // dump the values
        $contents = ob_get_contents(); // put the buffer into a variable
        ob_end_clean(); // end capture
        error_log($contents); // log contents of the result of var_dump( $object )
    }
}
?>
