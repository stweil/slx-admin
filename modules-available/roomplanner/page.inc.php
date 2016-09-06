<?php

class Page_Roomplanner extends Page
{
    protected function doPreprocess()
    {
        User::load();

        if (!User::hasPermission('superadmin')) {
            Message::addError('main.no-permission');
            Util::redirect('?do=Main');
        }
    }

    protected function doRender()
    {

        $locationid = Request::get('locationid', null, 'integer');

        if ($locationid === null) { die('please specify locationid'); }


        $furniture      = $this->getFurniture($locationid);
        $subnetMachines = $this->getPotentialMachines($locationid);
        $machinesOnPlan = $this->getMachinesOnPlan($locationid);

        $action = Request::any('action', 'show', 'string');

        $roomConfig = array_merge($furniture, $machinesOnPlan);


        if ($action === 'show') {
            /* do nothing */
            Render::addTemplate('page', [
                'subnetMachines' => json_encode($subnetMachines),
                'locationid' => $locationid,
                'roomConfiguration' => json_encode($roomConfig)]);
        } else if ($action === 'save') {
            /* save */
            $config = Request::post('serializedRoom', null, 'string');
            $config = json_decode($config, true);
            $this->saveRoomConfig($locationid, $config['furniture']);
            $this->saveComputerConfig($locationid, $config['computers'], $machinesOnPlan);
            Util::redirect("?do=roomplanner&locationid=$locationid&action=show");
        }

    }

    protected function doAjax()
    {
        $action = Request::get('action', null, 'string');

        if ($action === 'getmachines') {
            $query = Request::get('query', null, 'string');

            /* the query could be anything: UUID, IP or macaddr */
//			$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
//				. ', MATCH (machineuuid, macaddr, clientip, hostname) AGAINST (:query) AS relevance '
//				. 'FROM machine '
//				. 'WHERE MATCH (machineuuid, macaddr, clientip, hostname) AGAINST (:query) '
//				. 'ORDER BY relevance DESC '
//				. 'LIMIT 5'
//				, ['query' => $query]);
//
            $result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
                .'FROM machine '
                .'WHERE machineuuid LIKE :query '
                .' OR macaddr  	 LIKE :query '
                .' OR clientip    LIKE :query '
                .' OR hostname	 LIKE :query ', ['query' => "%$query%"]);

            $returnObject = ['machines' => []];

            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $returnObject['machines'][] = $row;
            }
            echo json_encode($returnObject);
        }
    }

    protected function saveComputerConfig($locationid, $computers, $oldComputers) {

        $oldUuids = [];
        /* collect all uuids  from the old computers */
        foreach($oldComputers['computers'] as $c) {
            $oldUuids[] = $c['muuid'];
        }

        $newUuids = [];
        foreach($computers as $computer) {
            $newUuids[] =  $computer['muuid'];

            $position = json_encode(['gridRow' => $computer['gridRow'],
                                     'gridCol' => $computer['gridCol'],
                                     'itemlook' => $computer['itemlook']]);

            Database::exec('UPDATE machine SET position = :position, locationid = :locationid WHERE machineuuid = :muuid',
            ['locationid' => $locationid, 'muuid' => $computer['muuid'], 'position' => $position]);
        }

        $toDelete = array_diff($oldUuids, $newUuids);

        foreach($toDelete as $d) {
            Database::exec("UPDATE machine SET position = '', locationid = NULL WHERE machineuuid = :uuid", ['uuid' => $d]);
        }
    }
    protected function saveRoomConfig($locationid, $furniture) {
        $obj = json_encode(['furniture' => $furniture]);
        Database::exec('INSERT INTO location_roomplan (locationid, roomplan) VALUES (:locationid, :roomplan) ON DUPLICATE KEY UPDATE roomplan=:roomplan',
            ['locationid' => $locationid,
             'roomplan' => $obj]);
    }

    protected function getFurniture($locationid) {
        $config = Database::queryFirst('SELECT roomplan FROM location_roomplan WHERE locationid = :locationid', ['locationid' => $locationid]);
        if ($config === false) {
        	  return array();
        }
        $config = json_decode($config['roomplan'], true);
        return $config;
    }
    protected function getMachinesOnPlan($locationid) {
        $result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname, position FROM machine WHERE locationid = :locationid',
            ['locationid' => $locationid]);
        $machines = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $machine = [];
            $pos = json_decode($row['position'], true);
			  // TODO: Check if pos is valid (has required keys)

            $machine['muuid'] = $row['machineuuid'];
            $machine['ip']      = $row['clientip'];
            $machine['mac_address'] = $row['macaddr'];
            $machine['hostname'] = $row['hostname'];
            $machine['gridRow'] = (int) $pos['gridRow'];
            $machine['gridCol'] = (int) $pos['gridCol'];
            $machine['itemlook'] = $pos['itemlook'];
            $machine['data-width'] = 100;
            $machine['data-height'] = 100;
            $machines[] = $machine;
        }
        return ['computers' => $machines];
    }

    protected function getPotentialMachines($locationid)
    {
        $result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
            .'FROM machine INNER JOIN subnet ON (INET_ATON(clientip) BETWEEN startaddr AND endaddr) '
            .'WHERE subnet.locationid = :locationid', ['locationid' => $locationid]);

        $machines = [];

        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $row['combined'] = implode(' ', array_values($row));
            $machines[] = $row;
        }

        return $machines;
    }
}
