<?php

/* base class with rudimentary SQL generation abilities.
 * WARNING: argument is escaped, but $column and $operator are passed unfiltered into SQL */
class Filter
{
    /**
     * Delimiter for js_selectize filters
     */
    const DELIMITER = '~,~';

    public $column;
    public $operator;
    public $argument;

    public function __construct($column, $operator, $argument = null)
    {
        $this->column = trim($column);
        $this->operator = trim($operator);
        $this->argument = trim($argument);
    }
    /* returns a where clause and adds needed operators to the passed array */
    public function whereClause(&$args, &$joins)
    {
        global $unique_key;
        $key = $this->column.'_arg' . ($unique_key++);

        /* check if we have to do some parsing*/
        if (Page_Statistics::$columns[$this->column]['type'] == 'date') {
                $args[$key] = strtotime($this->argument);
        } else {
                $args[$key] = $this->argument;
        }

        $op = $this->operator;
        if ($this->operator == '~') {
            $op = 'LIKE';
        } elseif ($this->operator == '!~') {
            $op = 'NOT LIKE';
        }

        return $this->column.' '.$op.' :'.$key;
    }
    /* parse a query into an array of filters */
    public static function parseQuery($query)
    {
        $operators = ['<=', '>=', '!=', '!~', '=', '~', '<', '>'];
        $filters = [];
        foreach (explode(self::DELIMITER, $query) as $q) {
            $q = trim($q);
                    /* find position of first operator */
                    $pos = 10000;
            $operator = false;
            foreach ($operators as $op) {
                $newpos = strpos($q, $op);
                if ($newpos > -1 && ($newpos < $pos)) {
                    $pos = $newpos;
                    $operator = $op;
                }
            }
            if ($pos == 10000) {
                error_log("couldn't find operator in segment ".$q);
                            /* TODO */
                            continue;
            }
            $lhs = trim(substr($q, 0, $pos));
            $rhs = trim(substr($q, $pos + strlen($operator)));

            if ($lhs == 'gbram') {
                $filters[] = new RamGbFilter($operator, $rhs);
            } elseif ($lhs == 'state') {
                    error_log('new state filter with ' . $rhs);
                $filters[] = new StateFilter($operator, $rhs);
            } elseif ($lhs == 'hddgb') {
                $filters[] = new Id44Filter($operator, $rhs);
            } elseif ($lhs == 'location') {
                $filters[] = new LocationFilter($operator, $rhs);
            } elseif ($lhs == 'subnet') {
                    $filters[] = new SubnetFilter($operator, $rhs);
            } else {
                if (array_key_exists($lhs, Page_Statistics::$columns) && Page_Statistics::$columns[$lhs]['column']) {
                    $filters[] = new Filter($lhs, $operator, $rhs);
                } else {
                    Message::addError('invalid-filter-key', $lhs);
                }
            }
        }

        return $filters;
    }
}

class RamGbFilter extends Filter
{
    public function __construct($operator, $argument)
    {
        parent::__construct('mbram', $operator, $argument);
    }
    public function whereClause(&$args, &$joins)
    {
        global $SIZE_RAM;
        $lower = floor(Page_Statistics::findBestValue($SIZE_RAM, (int) $this->argument, false) * 1024 - 100);
        $upper = ceil(Page_Statistics::findBestValue($SIZE_RAM, (int) $this->argument, true) * 1024 + 100);
        if ($this->operator == '=') {
            return " mbram BETWEEN $lower AND $upper";
        } elseif ($this->operator == '<') {
            return " mbram < $lower";
        } elseif ($this->operator  == '<=') {
            return " mbram <= $upper";
        } elseif ($this->operator == '>') {
            return " mbram > $upper";
        } elseif ($this->operator == '>=') {
            return " mbram >= $lower";
        } elseif ($this->operator == '!=') {
            return " (mbram < $lower OR mbram > $upper)";
        } else {
            error_log("unimplemented operator in RamGbFilter: $this->operator");

            return ' 1';
        }
    }
}
class Id44Filter extends Filter
{
    public function __construct($operator, $argument)
    {
        parent::__construct('id44mb', $operator, $argument);
    }
    public function whereClause(&$args, &$joins)
    {
        global $SIZE_ID44;
        $lower = floor(Page_Statistics::findBestValue($SIZE_ID44, $this->argument, false) * 1024 - 100);
        $upper = ceil(Page_Statistics::findBestValue($SIZE_ID44, $this->argument, true) * 1024 + 100);

        if ($this->operator == '=') {
            return " id44mb BETWEEN $lower AND $upper";
        } elseif ($this->operator == '!=') {
            return " id44mb < $lower OR id44mb > $upper";
        } elseif ($this->operator == '<=') {
            return " id44mb < $upper";
        } elseif ($this->operator == '>=') {
            return " id44mb > $lower";
        } elseif ($this->operator == '<') {
            return " id44mb < $lower";
        } elseif ($this->operator == '>') {
            return " id44mb > $upper";
        } else {
            error_log("unimplemented operator in Id44Filter: $this->operator");

            return ' 1';
        }
    }
}
class StateFilter extends Filter
{
    public function __construct($operator, $argument)
    {
        $this->operator = $operator;
        $this->argument = $argument;
    }

    public function whereClause(&$args, &$joins)
    {
        $neg = $this->operator == '!=' ? 'NOT ' : '';
        if ($this->argument === 'on') {
            return " $neg (lastseen + 600 > UNIX_TIMESTAMP() ) ";
        } elseif ($this->argument === 'off') {
            return " $neg (lastseen + 600 < UNIX_TIMESTAMP() ) ";
        } elseif ($this->argument === 'idle') {
            return " $neg (lastseen + 600 > UNIX_TIMESTAMP() AND logintime = 0 ) ";
        } elseif ($this->argument === 'occupied') {
            return " $neg (lastseen + 600 > UNIX_TIMESTAMP() AND logintime <> 0 ) ";
        } else {
            Message::addError('invalid-filter-argument', 'state', $this->argument);
            return ' 1';
        }
    }
}

class LocationFilter extends Filter
{
        public function __construct($operator, $argument) {
                parent::__construct('locationid', $operator, $argument);
        }

        public function whereClause(&$args, &$joins) {
             settype($this->argument, 'int');
            if ($this->argument === 0) {
                 $joins[] = 'LEFT JOIN subnet s ON (INET_ATON(machine.clientip) BETWEEN s.startaddr AND s.endaddr)';
                 return 'machine.locationid IS NULL AND s.locationid IS NULL';
             } else {
                 $joins[] = ' INNER JOIN subnet ON (INET_ATON(clientip) BETWEEN startaddr AND endaddr) ';
                 $args['lid'] = $this->argument;
                 $neg = $this->operator == '=' ? '' : 'NOT';
                 return "$neg (subnet.locationid = :lid OR machine.locationid = :lid)";
             }
        }
}

class SubnetFilter extends Filter
{
        public function __construct($operator, $argument) {
                parent::__construct(null, $operator, $argument);
        }
        public function whereClause(&$args, &$joins) {
                $argument = preg_replace('/[^0-9\.:]/', '', $this->argument);
                return " clientip LIKE '$argument%'";
        }
}

