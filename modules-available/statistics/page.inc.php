<?php

global $STATS_COLORS, $SIZE_ID44, $SIZE_RAM;
global $unique_key;
$STATS_COLORS = array();
for ($i = 0; $i < 10; ++$i) {
    $STATS_COLORS[] = '#55'.sprintf('%02s%02s', dechex((($i + 1) * ($i + 1)) / .3922), dechex(abs((5 - $i) * 51)));
}
//$STATS_COLORS = array('#57e', '#ee8', '#5ae', '#fb7', '#6d7', '#e77', '#3af', '#666', '#e0e', '#999');
$SIZE_ID44 = array(0, 8, 16, 24, 30, 40, 50, 60, 80, 100, 120, 150, 180, 250, 300, 350, 400, 450, 500);
$SIZE_RAM = array(1, 2, 3, 4, 6, 8, 10, 12, 16, 24, 32, 48, 64, 96, 128, 192, 256, 320, 480, 512, 768, 1024);

class Page_Statistics extends Page
{
    /* some constants, TODO: Find a better place */
    public static $op_nominal;
    public static $op_ordinal;
    public static $op_element;
    public static $op_stringcmp;
    public static $columns;

        /* PHP sucks, no static, const array definitions... Or am I missing something? */
        public function initConstants()
        {
                Page_Statistics::$op_nominal = ['!=', '='];
                Page_Statistics::$op_ordinal = ['!=', '<=', '>=', '=', '<', '>'];
                Page_Statistics::$op_element = ['in', 'not in'];
                Page_Statistics::$op_stringcmp = ['!~', '~', '=', '!='];

            Page_Statistics::$columns = [
                        'machineuuid' => [
                                'op' => Page_Statistics::$op_nominal,
                                'type' => 'string',
                                'column' => true,
                        ],
                        'macaddr' => [
                                'op' => Page_Statistics::$op_nominal,
                                'type' => 'string',
                                'column' => true,
                        ],
                        'firstseen' => [
                                'op' => Page_Statistics::$op_ordinal,
                                'type' => 'date',
                                'column' => true,
                        ],
                        'lastseen' => [
                                'op' => Page_Statistics::$op_ordinal,
                                'type' => 'date',
                                'column' => true,
                        ],
                        'logintime' => [
                                'op' => Page_Statistics::$op_ordinal,
                                'type' => 'date',
                                'column' => true,
                        ],
                        'position' => [
                                'op' => Page_Statistics::$op_nominal,
                                'type' => 'string',
                                'column' => true,
                        ],
                        'realcores' => [
                                'op' => Page_Statistics::$op_ordinal,
                                'type' => 'int',
                                'column' => true,
                        ],
//                        'mbram' => [
//                                'op' => Page_Statistics::$op_ordinal,
//                                'type' => 'int',
//                                'column' => true,
//                        ],
                        'systemmodel' => [
                                'op' => Page_Statistics::$op_stringcmp,
                                'type' => 'string',
                                'column' => true,
                        ],
                        'cpumodel' => [
                                'op' => Page_Statistics::$op_stringcmp,
                                'type' => 'string',
                                'column' => true,
                        ],
                        'hddgb' => [
                                'op' => Page_Statistics::$op_ordinal,
                                'type' => 'int',
                                'column' => false,
                                'map_sort' => 'id44mb'
                        ],
                        'gbram' => [
                                'op' => Page_Statistics::$op_ordinal,
                                'type' => 'int',
                                'map_sort' => 'mbram',
                                'column' => false,
                        ],
                        'kvmstate' => [
                                'op' => Page_Statistics::$op_nominal,
                                'type' => 'enum',
                                'column' => true,
                                'values' => ['ENABLED', 'DISABLED', 'UNSUPPORTED']
                        ],
                        'badsectors' => [
                                'op' => Page_Statistics::$op_ordinal,
                                'type' => 'int',
                                'column' => true
                        ],
                        'clientip'        => [
                                'op' => Page_Statistics::$op_nominal,
                                'type' => 'string',
                                'column' => true
                        ],
                        'subnet'        => [
                                'op' => Page_Statistics::$op_nominal,
                                'type' => 'string',
                                'column' => false
                        ]
                ];
                /* TODO ... */
        }
    protected function doPreprocess()
    {
        $this->initConstants();
        User::load();
        if (!User::hasPermission('superadmin')) {
            Message::addError('main.no-permission');
            Util::redirect('?do=Main');
        }
        $action = Request::post('action');
        if ($action === 'setnotes') {
            $uuid = Request::post('uuid', '', 'string');
            $text = Request::post('content', '', 'string');
            if (empty($text)) {
                $text = null;
            }
            Database::exec('UPDATE machine SET notes = :text WHERE machineuuid = :uuid', array(
                'uuid' => $uuid,
                'text' => $text,
            ));
            Message::addSuccess('notes-saved');
            Util::redirect('?do=Statistics&uuid='.$uuid);
        }
    }

    protected function doRender()
    {
        $uuid = Request::get('uuid', false, 'string');
        if ($uuid !== false) {
            $this->showMachine($uuid);

            return;
        }
        $filter = Request::get('filter', false, 'string');
        $show = Request::get('show', 'stat', 'string');
        if ($filter !== false || $show == 'list') {
            $argument = Request::get('argument', false, 'string');
            $this->showMachineList($filter, $argument);

            return;
        }
        Render::openTag('div', array('class' => 'row'));
        $this->showSummary();
        $this->showMemory();
        $this->showId44();
        $this->showKvmState();
        $this->showLatestMachines();
        $this->showSystemModels();
        Render::closeTag('div');
    }

    private function capChart(&$json, $cutoff, $minSlice = 0.015)
    {
        $total = 0;
        foreach ($json as $entry) {
            $total += $entry['value'];
        }
        if ($total === 0) {
            return;
        }
        $cap = ceil($total * $cutoff);
        $accounted = 0;
        $id = 0;
        foreach ($json as $entry) {
            if (($accounted >= $cap || $entry['value'] / $total < $minSlice) && $id >= 3) {
                break;
            }
            ++$id;
            $accounted += $entry['value'];
        }
        $json = array_slice($json, 0, $id);
        if ($accounted / $total < 0.99) {
            $json[] = array(
                'color' => '#eee',
                'label' => 'invalid',
                'value' => ($total - $accounted),
            );
        }
    }

    private function showSummary()
    {
        $cutoff = time() - 86400 * 30;
        $online = time() - 610;
        $known = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE lastseen > $cutoff");
        $on = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE lastseen > $online");
        $used = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE lastseen > $online AND logintime <> 0");
        $hdd = Database::queryFirst("SELECT Count(*) AS val FROM machine WHERE badsectors > 10 AND lastseen > $cutoff");
        if ($on['val'] != 0) {
            $usedpercent = round($used['val'] / $on['val'] * 100);
        } else {
            $usedpercent = 0;
        }
        $data = array(
            'known' => $known['val'],
            'online' => $on['val'],
            'used' => $used['val'],
            'usedpercent' => $usedpercent,
            'badhdd' => $hdd['val'],
        );
        // Graph
        $cutoff = time() - 2 * 86400;
        $res = Database::simpleQuery("SELECT dateline, data FROM statistic WHERE typeid = '~stats' AND dateline > $cutoff ORDER BY dateline ASC");
        $labels = array();
        $points1 = array('data' => array(), 'label' => 'Online', 'fillColor' => '#efe', 'strokeColor' => '#aea', 'pointColor' => '#7e7', 'pointStrokeColor' => '#fff', 'pointHighlightFill' => '#fff', 'pointHighlightStroke' => '#7e7');
        $points2 = array('data' => array(), 'label' => 'In use', 'fillColor' => '#fee', 'strokeColor' => '#eaa', 'pointColor' => '#e77', 'pointStrokeColor' => '#fff', 'pointHighlightFill' => '#fff', 'pointHighlightStroke' => '#e77');
        $sum = 0;
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $x = explode('#', $row['data']);
            if ($sum === 0) {
                $labels[] = date('H:i', $row['dateline']);
            } else {
                $x[1] = max($x[1], array_pop($points1['data']));
                $x[2] = max($x[2], array_pop($points2['data']));
            }
            $points1['data'][] = $x[1];
            $points2['data'][] = $x[2];
            ++$sum;
            if ($sum === 12) {
                $sum = 0;
            }
        }
        $data['json'] = json_encode(array('labels' => $labels, 'datasets' => array($points1, $points2)));
        // Draw
        Render::addTemplate('summary', $data);
    }

    private function showSystemModels()
    {
        global $STATS_COLORS;
        $res = Database::simpleQuery('SELECT systemmodel, Round(AVG(realcores)) AS cores, Count(*) AS `count` FROM machine'
            .' GROUP BY systemmodel ORDER BY `count` DESC, systemmodel ASC');
        $lines = array();
        $json = array();
        $id = 0;
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['systemmodel'])) {
                continue;
            }
            settype($row['count'], 'integer');
            $row['id'] = 'systemid'.$id;
            $row['urlsystemmodel'] = urlencode($row['systemmodel']);
            $lines[] = $row;
            $json[] = array(
                'color' => $STATS_COLORS[$id % count($STATS_COLORS)],
                'label' => 'systemid'.$id,
                'value' => $row['count'],
            );
            ++$id;
        }
        $this->capChart($json, 0.92);
        Render::addTemplate('cpumodels', array('rows' => $lines, 'json' => json_encode($json)));
    }

    private function showMemory()
    {
        global $STATS_COLORS, $SIZE_RAM;
        $res = Database::simpleQuery('SELECT mbram, Count(*) AS `count` FROM machine GROUP BY mbram');
        $lines = array();
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $gb = ceil($row['mbram'] / 1024);
            for ($i = 1; $i < count($SIZE_RAM); ++$i) {
                if ($SIZE_RAM[$i] < $gb) {
                    continue;
                }
                if ($SIZE_RAM[$i] - $gb >= $gb - $SIZE_RAM[$i - 1]) {
                    --$i;
                }
                $gb = $SIZE_RAM[$i];
                break;
            }
            if (isset($lines[$gb])) {
                $lines[$gb] += $row['count'];
            } else {
                $lines[$gb] = $row['count'];
            }
        }
        asort($lines);
        $data = array('rows' => array());
        $json = array();
        $id = 0;
        foreach (array_reverse($lines, true) as $k => $v) {
            $data['rows'][] = array('gb' => $k, 'count' => $v, 'class' => $this->ramColorClass($k * 1024));
            $json[] = array(
                'color' => $STATS_COLORS[$id % count($STATS_COLORS)],
                'label' => (string) $k,
                'value' => $v,
            );
            ++$id;
        }
        $this->capChart($json, 0.92);
        $data['json'] = json_encode($json);
        Render::addTemplate('memory', $data);
    }

    private function showKvmState()
    {
        $colors = array('UNKNOWN' => '#666', 'UNSUPPORTED' => '#ea5', 'DISABLED' => '#e55', 'ENABLED' => '#6d6');
        $res = Database::simpleQuery('SELECT kvmstate, Count(*) AS `count` FROM machine GROUP BY kvmstate ORDER BY `count` DESC');
        $lines = array();
        $json = array();
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $lines[] = $row;
            $json[] = array(
                'color' => isset($colors[$row['kvmstate']]) ? $colors[$row['kvmstate']] : '#000',
                'label' => $row['kvmstate'],
                'value' => $row['count'],
            );
        }
        Render::addTemplate('kvmstate', array('rows' => $lines, 'json' => json_encode($json)));
    }

    private function showId44()
    {
        global $STATS_COLORS, $SIZE_ID44;
        $res = Database::simpleQuery('SELECT id44mb, Count(*) AS `count` FROM machine GROUP BY id44mb');
        $lines = array();
        $total = 0;
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $total += $row['count'];
            $gb = ceil($row['id44mb'] / 1024);
            for ($i = 1; $i < count($SIZE_ID44); ++$i) {
                if ($SIZE_ID44[$i] < $gb) {
                    continue;
                }
                if ($SIZE_ID44[$i] - $gb >= $gb - $SIZE_ID44[$i - 1]) {
                    --$i;
                }
                $gb = $SIZE_ID44[$i];
                break;
            }
            if (isset($lines[$gb])) {
                $lines[$gb] += $row['count'];
            } else {
                $lines[$gb] = $row['count'];
            }
        }
        asort($lines);
        $data = array('rows' => array());
        $json = array();
        $id = 0;
        foreach (array_reverse($lines, true) as $k => $v) {
            $data['rows'][] = array('gb' => $k, 'count' => $v, 'class' => $this->hddColorClass($k));
            if ($k === 0) {
                $color = '#e55';
            } else {
                $color = $STATS_COLORS[$id++ % count($STATS_COLORS)];
            }
            $json[] = array(
                'color' => $color,
                'label' => (string) $k,
                'value' => $v,
            );
        }
        $this->capChart($json, 0.95);
        $data['json'] = json_encode($json);
        Render::addTemplate('id44', $data);
    }

    private function showLatestMachines()
    {
        $data = array('cutoff' => ceil(time() / 3600) * 3600 - 86400 * 7);
        $res = Database::simpleQuery('SELECT machineuuid, clientip, hostname, firstseen, mbram, kvmstate, id44mb FROM machine'
            .' WHERE firstseen > :cutoff ORDER BY firstseen DESC LIMIT 32', $data);
        $rows = array();
        $count = 0;
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['hostname'])) {
                $row['hostname'] = $row['clientip'];
            }
            $row['firstseen'] = date('d.m. H:i', $row['firstseen']);
            $row['gbram'] = round(round($row['mbram'] / 500) / 2, 1); // Trial and error until we got "expected" rounding..
            $row['gbtmp'] = round($row['id44mb'] / 1024);
            $row['ramclass'] = $this->ramColorClass($row['mbram']);
            $row['kvmclass'] = $this->kvmColorClass($row['kvmstate']);
            $row['hddclass'] = $this->hddColorClass($row['gbtmp']);
            $row['kvmicon'] = $row['kvmstate'] === 'ENABLED' ? '✓' : '✗';
            if (++$count > 5) {
                $row['style'] = 'display:none';
            }
            $rows[] = $row;
        }
        Render::addTemplate('newclients', array('rows' => $rows, 'openbutton' => $count > 5));
    }


    private function showMachineList($filter, $argument)
    {
        $query = Request::any('filters');
        $sortColumn = Request::any('sortColumn');
        $sortDirection = Request::any('sortDirection');

        $filters = Filter::parseQuery($query);

        /* generate where clause & arguments */
        $where = '';
        $joins  = [];
        $args = [];
        error_log('number of filters: '.count($filters));
        if (empty($filters)) {
            $where = ' 1 ';
        } else {
            foreach ($filters as $filter) {
                $sep = ($where != '' ?  ' AND ' : '');
                $where .=  $sep.$filter->whereClause($args, $joins);
            }
        }
        $join = implode('', array_unique($joins));


        if(!in_array($sortDirection, ['ASC', 'DESC'])) {
                $sortDirection = 'ASC';
        }
        if (array_key_exists($sortColumn, Page_Statistics::$columns)) {
                if (array_key_exists('map_sort', Page_Statistics::$columns[$sortColumn])) {
                        /* use the column mapping */
                        $actualCol = Page_Statistics::$columns[$sortColumn]['map_sort'];
                        $sort = " ORDER BY $actualCol $sortDirection";
                } else {
                        $sort = " ORDER BY $sortColumn $sortDirection";
                }
        } else {
                $sort = "";
        }


        // /* find the sorting */
        // $sort = explode(' ', Request::any('sort', 'clientip asc'));
        // $sort[1] = strtoupper($sort[1]);
        // if (array_key_exists($sort[0], Page_Statistics::$columns) && ($sort[1] == 'ASC' || $sort[1] == 'DESC')) {

        //         /* check map_sort mapping */
        //         if(array_key_exists('map_sort', Page_Statistics::$columns[$sort[0]])) {
        //                 $sort[0] = Page_Statistics::$columns[$sort[0]]['map_sort'];
        //         }
        //         $sort = "ORDER BY " . $sort[0] . ' ' .  $sort[1];
        // } else {
        //         $sort = "";
        // }

        error_log(" JOIN is " . $join);
        error_log($where);
        global $SIZE_RAM, $SIZE_ID44;
        // $filters = array('cpumodel', 'realcores', 'kvmstate', 'clientip', 'macaddr', 'machineuuid', 'systemmodel');
        // if (in_array($filter, $filters)) {
        //     // Simple filters mapping into db
        //     $where = " $filter = :argument";
        //     $args = array('argument' => $argument);
        // } elseif ($filter === 'gbram') {
        //     // Memory by rounded GB
        //     $lower = floor($this->findBestValue($SIZE_RAM, $argument, false) * 1024 - 100);
        //     $upper = ceil($this->findBestValue($SIZE_RAM, $argument, true) * 1024 + 100);
        //     $where = " mbram BETWEEN $lower AND $upper";
        //     $args = array();
        // } elseif ($filter === 'hddgb') {
        //     // HDD by rounded GB
        //     $lower = floor($this->findBestValue($SIZE_ID44, $argument, false) * 1024 - 100);
        //     $upper = ceil($this->findBestValue($SIZE_ID44, $argument, true) * 1024 + 100);
        //     $where = " id44mb BETWEEN $lower AND $upper";
        //     $args = array();
        // } elseif ($filter === 'subnet') {
        //     $argument = preg_replace('/[^0-9\.:]/', '', $argument);
        //     $where = " clientip LIKE '$argument%'";
        //     $args = array();
        // } elseif ($filter === 'badsectors') {
        //     $where = ' badsectors >= :argument ';
        //     $args = array('argument' => $argument);
        // } elseif ($filter === 'state') {
        //     if ($argument === 'on') {
        //         $where = ' lastseen + 600 > UNIX_TIMESTAMP() ';
        //     } elseif ($argument === 'off') {
        //         $where = ' lastseen + 600 < UNIX_TIMESTAMP() ';
        //     } elseif ($argument === 'idle') {
        //         $where = ' lastseen + 600 > UNIX_TIMESTAMP() AND logintime = 0 ';
        //     } elseif ($argument === 'occupied') {
        //         $where = ' lastseen + 600 > UNIX_TIMESTAMP() AND logintime <> 0 ';
        //     } else {
        //         Message::addError('invalid-filter');

        //         return;
        //     }
        // } elseif ($filter === 'location') {
        //     settype($argument, 'int');
        //     if ($argument === 0) {
        //         $where = 'machine.locationid IS NULL AND s.locationid IS NULL';
        //         $join = 'LEFT JOIN subnet s ON (INET_ATON(machine.clientip) BETWEEN s.startaddr AND s.endaddr)';
        //     } else {
        //         $where = 'subnet.locationid = :lid OR machine.locationid = :lid';
        //         $join = ' INNER JOIN subnet ON (INET_ATON(clientip) BETWEEN startaddr AND endaddr) ';
        //         $args = array('lid' => $argument);
        //     }
        // } else {
        //     //Message::addError('invalid-filter');
        //     // return;
        //     $where = '1';
        //     $args = [];
        // }

        // $where and $args
        $res = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, firstseen, lastseen,'
            .' logintime, lastboot, realcores, mbram, kvmstate, cpumodel, id44mb, hostname, notes IS NOT NULL AS hasnotes, badsectors FROM machine'
            ." $join WHERE $where $sort", $args);
        $rows = array();
        $NOW = time();
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            if ($NOW - $row['lastseen'] > 610) {
                $row['state_off'] = true;
            } elseif ($row['logintime'] == 0) {
                $row['state_idle'] = true;
            } else {
                $row['state_occupied'] = true;
            }
            //$row['firstseen'] = date('d.m.Y H:i', $row['firstseen']);
            $row['lastseen'] = date('d.m. H:i', $row['lastseen']);
            //$row['lastboot'] = date('d.m. H:i', $row['lastboot']);
            $row['gbram'] = round(round($row['mbram'] / 500) / 2, 1); // Trial and error until we got "expected" rounding..
            $row['gbtmp'] = round($row['id44mb'] / 1024);
            $octets = explode('.', $row['clientip']);
            if (count($octets) === 4) {
                $row['subnet'] = "$octets[0].$octets[1].$octets[2].";
                $row['lastoctet'] = $octets[3];
            }
            $row['ramclass'] = $this->ramColorClass($row['mbram']);
            $row['kvmclass'] = $this->kvmColorClass($row['kvmstate']);
            $row['hddclass'] = $this->hddColorClass($row['gbtmp']);
            if (empty($row['hostname'])) {
                $row['hostname'] = $row['clientip'];
            }
            $rows[] = $row;
        }
        Render::addTemplate('clientlist', array('rows' => $rows, 'filter' => $filter,
                'query' => $query, 'sortDirection' => $sortDirection, 'sortColumn' => $sortColumn, 'argument' => $argument, 'columns' => json_encode(Page_Statistics::$columns), ));
    }

    private function ramColorClass($mb)
    {
        if ($mb < 1500) {
            return 'danger';
        }
        if ($mb < 2500) {
            return 'warning';
        }

        return '';
    }

    private function kvmColorClass($state)
    {
        if ($state === 'DISABLED') {
            return 'danger';
        }
        if ($state  === 'UNKNOWN' || $state === 'UNSUPPORTED') {
            return 'warning';
        }

        return '';
    }

    private function hddColorClass($gb)
    {
        if ($gb < 7) {
            return 'danger';
        }
        if ($gb < 25) {
            return 'warning';
        }

        return '';
    }

    public static function findBestValue($array, $value, $up)
    {
        $best = 0;
        for ($i = 0; $i < count($array); ++$i) {
            if (abs($array[$i] - $value) < abs($array[$best] - $value)) {
                $best = $i;
            }
        }
        if (!$up && $best === 0) {
            return $array[0];
        }
        if ($up && $best + 1 === count($array)) {
            return $array[$best];
        }
        if ($up) {
            return ($array[$best] + $array[$best + 1]) / 2;
        }

        return ($array[$best] + $array[$best - 1]) / 2;
    }

    private function fillSessionInfo(&$row)
    {
        $res = Database::simpleQuery('SELECT dateline, username, data FROM statistic'
            ." WHERE clientip = :ip AND typeid = '.vmchooser-session-name'"
            .' AND dateline BETWEEN :start AND :end', array(
                'ip' => $row['clientip'],
                'start' => $row['logintime'] - 60,
                'end' => $row['logintime'] + 300,
        ));
        $session = false;
        while ($r = $res->fetch(PDO::FETCH_ASSOC)) {
            if ($session === false || abs($session['dateline'] - $row['logintime']) > abs($r['dateline'] - $row['logintime'])) {
                $session = $r;
            }
        }
        if ($session !== false) {
            $row['session'] = $session['data'];
            $row['username'] = $session['username'];
        }
    }

    private function showMachine($uuid)
    {
        $client = Database::queryFirst('SELECT machineuuid, locationid, macaddr, clientip, firstseen, lastseen, logintime, lastboot,'
            .' mbram, kvmstate, cpumodel, id44mb, data, hostname, notes FROM machine WHERE machineuuid = :uuid',
            array('uuid' => $uuid));
        // Mangle fields
        $NOW = time();
        if ($NOW - $client['lastseen'] > 610) {
            $client['state_off'] = true;
        } elseif ($client['logintime'] == 0) {
            $client['state_idle'] = true;
        } else {
            $client['state_occupied'] = true;
            $this->fillSessionInfo($client);
        }
        $client['firstseen_s'] = date('d.m.Y H:i', $client['firstseen']);
        $client['lastseen_s'] = date('d.m.Y H:i', $client['lastseen']);
        $uptime = $NOW - $client['lastboot'];
        $client['lastboot_s'] = date('d.m.Y H:i', $client['lastboot']);
        if (!isset($client['state_off']) || !$client['state_off']) {
            $client['lastboot_s'] .= ' (Up '.floor($uptime / 86400).'d '.gmdate('H:i', $uptime).')';
        }
        $client['logintime_s'] = date('d.m.Y H:i', $client['logintime']);
        $client['gbram'] = round(round($client['mbram'] / 500) / 2, 1);
        $client['gbtmp'] = round($client['id44mb'] / 1024);
        $client['ramclass'] = $this->ramColorClass($client['mbram']);
        $client['kvmclass'] = $this->kvmColorClass($client['kvmstate']);
        $client['hddclass'] = $this->hddColorClass($client['gbtmp']);
        // Parse the giant blob of data
        $hdds = array();
        if (preg_match_all('/##### ([^#]+) #+$(.*?)^#####/ims', $client['data'].'########', $out, PREG_SET_ORDER)) {
            foreach ($out as $section) {
                if ($section[1] === 'CPU') {
                    $this->parseCpu($client, $section[2]);
                }
                if ($section[1] === 'dmidecode') {
                    $this->parseDmiDecode($client, $section[2]);
                }
                if ($section[1] === 'Partition tables') {
                    $this->parseHdd($hdds, $section[2]);
                }
                if ($section[1] === 'PCI ID') {
                    $client['lspci1'] = $client['lspci2'] = array();
                    $this->parsePci($client['lspci1'], $client['lspci2'], $section[2]);
                }
                if (isset($hdds['hdds']) && $section[1] === 'smartctl') {
                    // This currently required that the partition table section comes first...
                    $this->parseSmartctl($hdds['hdds'], $section[2]);
                }
            }
        }
        unset($client['data']);
        // Get locations
        if (Module::isAvailable('locations')) {
            if (is_null($client['locationid'])) {
                $client['locationid'] = Location::getFromIp($client['clientip']);
            }
            $locs = Location::getLocationsAssoc();
            $next = (int) $client['locationid'];
            $output = array();
            while (isset($locs[$next])) {
                array_unshift($output, $locs[$next]);
                $next = $locs[$next]['parentlocationid'];
            }
            $client['locations'] = $output;
        }
        // Throw output at user
        Render::addTemplate('machine-main', $client);
        // Sessions
        $NOW = time();
        $cutoff = $NOW - 86400 * 7;
        //if ($cutoff < $client['firstseen']) $cutoff = $client['firstseen'];
        $scale = 100 / ($NOW - $cutoff);
        $res = Database::simpleQuery('SELECT dateline, typeid, data FROM statistic'
            ." WHERE dateline > :cutoff AND typeid IN ('~session-length', '~offline-length') AND machineuuid = :uuid ORDER BY dateline ASC", array(
            'cutoff' => $cutoff - 86400 * 14,
            'uuid' => $uuid,
        ));
        $spans['rows'] = array();
        $spans['graph'] = '';
        $last = false;
        $first = true;
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            if ($first && $row['dateline'] > $cutoff && $client['lastboot'] > $cutoff) {
                // Special case: offline before
                $spans['graph'] .= '<div style="background:#444;left:0%;width:'.round((min($row['dateline'], $client['lastboot']) - $cutoff) * $scale, 2).'%">&nbsp;</div>';
            }
            $first = false;
            if ($row['dateline'] + $row['data'] < $cutoff || $row['data'] > 864000) {
                continue;
            }
            if ($last !== false && abs($last['dateline'] - $row['dateline']) < 30
                    && abs($last['data'] - $row['data']) < 30) {
                continue;
            }
            if ($last !== false && $last['dateline'] + $last['data'] > $row['dateline']) {
                $point = $last['dateline'] + $last['data'];
                $row['data'] -= ($point - $row['dateline']);
                $row['dateline'] = $point;
            }
            if ($row['dateline'] < $cutoff) {
                $row['data'] -= ($cutoff - $row['dateline']);
                $row['dateline'] = $cutoff;
            }
            $row['from'] = date('d.m. H:i', $row['dateline']);
            $row['duration'] = floor($row['data'] / 86400).'d '.gmdate('H:i', $row['data']);
            if ($row['typeid'] === '~offline-length') {
                $row['glyph'] = 'off';
                $color = '#444';
            } else {
                $row['glyph'] = 'user';
                $color = '#e77';
            }
            $spans['graph'] .= '<div style="background:'.$color.';left:'.round(($row['dateline'] - $cutoff) * $scale, 2).'%;width:'.round(($row['data']) * $scale, 2).'%">&nbsp;</div>';
            $spans['rows'][] = $row;
            $last = $row;
        }
        if ($first && $client['lastboot'] > $cutoff) {
            // Special case: offline before
            $spans['graph'] .= '<div style="background:#444;left:0%;width:'.round(($client['lastboot'] - $cutoff) * $scale, 2).'%">&nbsp;</div>';
        }
        if (isset($client['state_occupied'])) {
            $spans['graph'] .= '<div style="background:#e99;left:'.round(($client['logintime'] - $cutoff) * $scale, 2).'%;width:'.round(($NOW - $client['logintime'] + 900) * $scale, 2).'%">&nbsp;</div>';
        } elseif (isset($client['state_off'])) {
            $spans['graph'] .= '<div style="background:#444;left:'.round(($client['lastseen'] - $cutoff) * $scale, 2).'%;width:'.round(($NOW - $client['lastseen'] + 900) * $scale, 2).'%">&nbsp;</div>';
        }
        $t = explode('-', date('Y-n-j-G', $cutoff));
        if ($t[3] >= 8 && $t[3] <= 22) {
            $start = mktime(22, 0, 0, $t[1], $t[2], $t[0]);
        } else {
            $start = mktime(22, 0, 0, $t[1], $t[2] - 1, $t[0]);
        }
        for ($i = $start; $i < $NOW; $i += 86400) {
            $spans['graph'] .= '<div style="background:rgba(0,0,90,.2);left:'.round(($i - $cutoff) * $scale, 2).'%;width:'.round((10 * 3600) * $scale, 2).'%">&nbsp;</div>';
        }
        if (count($spans['rows']) > 10) {
            $spans['hasrows2'] = true;
            $spans['rows2'] = array_slice($spans['rows'], ceil(count($spans['rows']) / 2));
            $spans['rows'] = array_slice($spans['rows'], 0, ceil(count($spans['rows']) / 2));
        }
        Render::addTemplate('machine-usage', $spans);
        // Any hdds?
        if (!empty($hdds['hdds'])) {
            Render::addTemplate('machine-hdds', $hdds);
        }
        // Client log
        $lres = Database::simpleQuery('SELECT logid, dateline, logtypeid, clientip, description, extra FROM clientlog'
                .' WHERE clientip = :clientip ORDER BY logid DESC LIMIT 25', array('clientip' => $client['clientip']));
        $today = date('d.m.Y');
        $yesterday = date('d.m.Y', time() - 86400);
        $count = 0;
        $log = array();
        while ($row = $lres->fetch(PDO::FETCH_ASSOC)) {
            if (substr($row['description'], -5) === 'on :0' && strpos($row['description'], 'root logged') === false) {
                continue;
            }
            $day = date('d.m.Y', $row['dateline']);
            if ($day === $today) {
                $day = Dictionary::translate('lang_today');
            } elseif ($day === $yesterday) {
                $day = Dictionary::translate('lang_yesterday');
            }
            $row['date'] = $day.date(' H:i', $row['dateline']);
            $row['icon'] = $this->eventToIconName($row['logtypeid']);
            $log[] = $row;
            if (++$count === 10) {
                break;
            }
        }
        Render::addTemplate('syslog', array(
            'clientip' => $client['clientip'],
            'list' => $log,
        ));
        // Notes
        Render::addTemplate('machine-notes', $client);
    }

    private function eventToIconName($event)
    {
        switch ($event) {
        case 'session-open':
            return 'glyphicon-log-in';
        case 'session-close':
            return 'glyphicon-log-out';
        case 'partition-swap':
            return 'glyphicon-info-sign';
        case 'partition-temp':
        case 'smartctl-realloc':
            return 'glyphicon-exclamation-sign';
        default:
            return 'glyphicon-minus';
        }
    }

    private function parseCpu(&$row, $data)
    {
        if (0 >= preg_match_all('/^(.+):\s+(\d+)$/im', $data, $out, PREG_SET_ORDER)) {
            return;
        }
        foreach ($out as $entry) {
            $row[str_replace(' ', '', $entry[1])] = $entry[2];
        }
    }

    private function parseDmiDecode(&$row, $data)
    {
        $lines = preg_split("/[\r\n]+/", $data);
        $section = false;
        $ramOk = false;
        $ramForm = $ramType = $ramSpeed = $ramClockSpeed = false;
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            if ($line{0} !== "\t" && $line{0} !== ' ') {
                $section = $line;
                $ramOk = false;
                if (($ramForm || $ramType) && ($ramSpeed || $ramClockSpeed)) {
                    if (isset($row['ramtype']) && !$ramClockSpeed) {
                        continue;
                    }
                    $row['ramtype'] = $ramType.' '.$ramForm;
                    if ($ramClockSpeed) {
                        $row['ramtype'] .= ', '.$ramClockSpeed;
                    } elseif ($ramSpeed) {
                        $row['ramtype'] .= ', '.$ramSpeed;
                    }
                    $ramForm = false;
                    $ramType = false;
                    $ramClockSpeed = false;
                }
                continue;
            }
            if ($section === 'System Information' || $section === 'Base Board Information') {
                if (empty($row['pcmodel']) && preg_match('/^\s*Product Name: +(\S.+?) *$/i', $line, $out)) {
                    $row['pcmodel'] = $out[1];
                }
                if (empty($row['manufacturer']) && preg_match('/^\s*Manufacturer: +(\S.+?) *$/i', $line, $out)) {
                    $row['manufacturer'] = $out[1];
                }
            } elseif ($section === 'Physical Memory Array') {
                if (!$ramOk && preg_match('/Use: System Memory/i', $line)) {
                    $ramOk = true;
                }
                if ($ramOk && preg_match('/^\s*Number Of Devices: +(\S.+?) *$/i', $line, $out)) {
                    $row['ramslotcount'] = $out[1];
                }
                if ($ramOk && preg_match('/^\s*Maximum Capacity: +(\S.+?)\s*$/i', $line, $out)) {
                    $row['maxram'] = preg_replace('/([MGT])B/', '$1iB', $out[1]);
                }
            } elseif ($section === 'Memory Device') {
                if (preg_match('/^\s*Size:\s*(.*?)\s*$/i', $line, $out)) {
                    $row['extram'] = true;
                    if (preg_match('/(\d+)\s*(\w)i?B/i', $out[1], $out)) {
                        $out[2] = strtoupper($out[2]);
                        if ($out[2] === 'K' || ($out[2] === 'M' && $out[1] < 500)) {
                            $ramForm = $ramType = $ramSpeed = $ramClockSpeed = false;
                            continue;
                        }
                        if ($out[2] === 'M' && $out[1] >= 1024) {
                            $out[2] = 'G';
                            $out[1] = floor(($out[1] + 100) / 1024);
                        }
                        $row['ramslot'][]['size'] = $out[1].' '.strtoupper($out[2]).'iB';
                    } elseif (!isset($row['ramslot']) || (count($row['ramslot']) < 8 && (!isset($row['ramslotcount']) || $row['ramslotcount'] <= 8))) {
                        $row['ramslot'][]['size'] = '_____';
                    }
                }
                if (preg_match('/^\s*Form Factor:\s*(.*?)\s*$/i', $line, $out) && $out[1] !== 'Unknown') {
                    $ramForm = $out[1];
                }
                if (preg_match('/^\s*Type:\s*(.*?)\s*$/i', $line, $out) && $out[1] !== 'Unknown') {
                    $ramType = $out[1];
                }
                if (preg_match('/^\s*Speed:\s*(\d.*?)\s*$/i', $line, $out)) {
                    $ramSpeed = $out[1];
                }
                if (preg_match('/^\s*Configured Clock Speed:\s*(\d.*?)\s*$/i', $line, $out)) {
                    $ramClockSpeed = $out[1];
                }
            }
        }
        if (empty($row['ramslotcount'])) {
            $row['ramslotcount'] = count($row['ramslot']);
        }
    }

    private function parseHdd(&$row, $data)
    {
        $hdds = array();
        // Could have more than one disk - linear scan
        $lines = preg_split("/[\r\n]+/", $data);
        $dev = false;
        $i = 0;
        foreach ($lines as $line) {
            if (preg_match('/^Disk (\S+):.* (\d+) bytes/i', $line, $out)) {
                // disk total size and name
                unset($hdd);
                $unit = 0;
                $hdd = array(
                    'devid' => 'devid-'.++$i,
                    'dev' => $out[1],
                    'size' => round($out[2] / (1024 * 1024 * 1024)),
                    'used' => 0,
                    'partitions' => array(),
                    'json' => array(),
                );
                $hdds[] = &$hdd;
            } elseif (preg_match('/^Units =.*= (\d+) bytes/i', $line, $out)) {
                // Unit for start and end
                $unit = $out[1] / (1024 * 1024); // Convert so that multiplying by unit yields MiB
            } elseif (isset($hdd) && $unit !== 0 && preg_match(',^/dev/(\S+)\s+.*\s(\d+)[\+\-]?\s+(\d+)[\+\-]?\s+\d+[\+\-]?\s+([0-9a-f]+)\s+(.*)$,i', $line, $out)) {
                // Some partition
                $type = strtolower($out[4]);
                if ($type === '5' || $type === 'f' || $type === '85') {
                    continue;
                }
                $partsize = round(($out[3] - $out[2]) * $unit);
                $hdd['partitions'][] = array(
                    'id' => $out[1],
                    'name' => $out[1],
                    'size' => round($partsize / 1024, $partsize < 1024 ? 1 : 0),
                    'type' => ($type === '44' ? 'OpenSLX' : $out[5]),
                );
                $hdd['json'][] = array(
                    'label' => $out[1],
                    'value' => $partsize,
                    'color' => ($type === '44' ? '#4d4' : ($type === '82' ? '#48f' : '#e55')),
                );
                $hdd['used'] += $partsize;
            }
        }
        unset($hdd);
        $i = 0;
        foreach ($hdds as &$hdd) {
            $hdd['used'] = round($hdd['used'] / 1024);
            $free = $hdd['size'] - $hdd['used'];
            if ($free > 5) {
                $hdd['partitions'][] = array(
                    'id' => 'free-id-'.$i,
                    'name' => Dictionary::translate('unused'),
                    'size' => $free,
                    'type' => '-',
                );
                $hdd['json'][] = array(
                    'label' => 'free-id-'.$i,
                    'value' => $free * 1024,
                    'color' => '#aaa',
                );
                ++$i;
            }
            $hdd['json'] = json_encode($hdd['json']);
        }
        unset($hdd);
        $row['hdds'] = &$hdds;
    }

    private function parsePci(&$pci1, &$pci2, $data)
    {
        preg_match_all('/[a-f0-9\:\.]{7}\s+"(Class\s*)?(?<class>[a-f0-9]{4})"\s+"(?<ven>[a-f0-9]{4})"\s+"(?<dev>[a-f0-9]{4})"/is', $data, $out, PREG_SET_ORDER);
        $NOW = time();
        $pci = array();
        foreach ($out as $entry) {
            if (!isset($pci[$entry['class']])) {
                $class = 'c.'.$entry['class'];
                $res = $this->getPciId('CLASS', $class);
                if ($res === false || $res['dateline'] < $NOW) {
                    $pci[$entry['class']]['lookupClass'] = 'do-lookup';
                    $pci[$entry['class']]['class'] = $class;
                } else {
                    $pci[$entry['class']]['class'] = $res['value'];
                }
            }
            $new = array(
                'ven' => $entry['ven'],
                'dev' => $entry['ven'].':'.$entry['dev'],
            );
            $res = $this->getPciId('VENDOR', $new['ven']);
            if ($res === false || $res['dateline'] < $NOW) {
                $new['lookupVen'] = 'do-lookup';
            } else {
                $new['ven'] = $res['value'];
            }
            $res = $this->getPciId('DEVICE', $new['ven'].':'.$new['dev']);
            if ($res === false || $res['dateline'] < $NOW) {
                $new['lookupDev'] = 'do-lookup';
            } else {
                $new['dev'] = $res['value'];
            }
            $pci[$entry['class']]['entries'][] = $new;
        }
        ksort($pci);
        foreach ($pci as $class => $entry) {
            if ($class === '0300' || $class === '0200' || $class === '0403') {
                $pci1[] = $entry;
            } else {
                $pci2[] = $entry;
            }
        }
    }

    private function parseSmartctl(&$hdds, $data)
    {
        $lines = preg_split("/[\r\n]+/", $data);
        $i = 0;
        foreach ($lines as $line) {
            if (preg_match('/^NEXTHDD=(.+)$/', $line, $out)) {
                unset($dev);
                foreach ($hdds as &$hdd) {
                    if ($hdd['dev'] === $out[1]) {
                        $dev = &$hdd;
                    }
                }
                continue;
            }
            if (!isset($dev)) {
                continue;
            }
            if (preg_match('/^([A-Z][^:]+):\s*(.*)$/', $line, $out)) {
                $dev['s_'.preg_replace('/\s|-|_/', '', $out[1])] = $out[2];
            } elseif (preg_match('/^\s*\d+\s+(\S+)\s+\S+\s+\d+\s+\d+\s+\d+\s+\S+\s+(\d+)(\s|$)/', $line, $out)) {
                $dev['s_'.preg_replace('/\s|-|_/', '', $out[1])] = $out[2];
            }
        }
        // Format strings
        foreach ($hdds as &$hdd) {
            if (isset($hdd['s_PowerOnHours'])) {
                $hdd['PowerOnTime'] = '';
                $val = (int) $hdd['s_PowerOnHours'];
                if ($val > 8760) {
                    $hdd['PowerOnTime'] .= floor($val / 8760).'Y, ';
                    $val %= 8760;
                }
                if ($val > 720) {
                    $hdd['PowerOnTime'] .= floor($val / 720).'M, ';
                    $val %= 720;
                }
                if ($val > 24) {
                    $hdd['PowerOnTime'] .= floor($val / 24).'d, ';
                    $val %= 24;
                }
                $hdd['PowerOnTime'] .= $val.'h';
            }
        }
    }

    protected function doAjax()
    {
        $param = Request::any('lookup', false, 'string');
        if ($param === false) {
            die('No lookup given');
        }
        $add = '';
        if (preg_match('/^([a-f0-9]{4}):([a-f0-9]{4})$/', $param, $out)) {
            $cat = 'DEVICE';
            $host = $out[2].'.'.$out[1];
            $add = ' ('.$param.')';
        } elseif (preg_match('/^([a-f0-9]{4})$/', $param, $out)) {
            $cat = 'VENDOR';
            $host = $out[1];
        } elseif (preg_match('/^c\.([a-f0-9]{2})([a-f0-9]{2})$/', $param, $out)) {
            $cat = 'CLASS';
            $host = $out[2].'.'.$out[1].'.c';
        } else {
            die('Invalid format requested');
        }
        $cached = $this->getPciId($cat, $param);
        if ($cached !== false && $cached['dateline'] > time()) {
            echo $cached['value'], $add;
            exit;
        }
        $res = dns_get_record($host.'.pci.id.ucw.cz', DNS_TXT);
        if (is_array($res)) {
            foreach ($res as $entry) {
                if (isset($entry['txt']) && substr($entry['txt'], 0, 2) === 'i=') {
                    $string = substr($entry['txt'], 2);
                    $this->setPciId($cat, $param, $string);
                    echo $string, $add;
                    exit;
                }
            }
        }
        if ($cached !== false) {
            echo $cached['value'], $add;
            exit;
        }
        die('Not found');
    }

    private function getPciId($cat, $id)
    {
        return Database::queryFirst('SELECT value, dateline FROM pciid WHERE category = :cat AND id = :id LIMIT 1',
            array('cat' => $cat, 'id' => $id));
    }

    private function setPciId($cat, $id, $value)
    {
        Database::exec('INSERT INTO pciid (category, id, value, dateline) VALUES (:cat, :id, :value, :timeout)'
            .' ON DUPLICATE KEY UPDATE value = VALUES(value), dateline = VALUES(dateline)',
            array(
                'cat' => $cat,
                'id' => $id,
                'value' => $value,
                'timeout' => time() + mt_rand(10, 30) * 86400,
            ), true);
    }
}
