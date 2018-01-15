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
		$key = $this->column . '_arg' . ($unique_key++);
		$addendum = '';

		/* check if we have to do some parsing*/
		if (Page_Statistics::$columns[$this->column]['type'] === 'date') {
			$args[$key] = strtotime($this->argument);
		} else {
			$args[$key] = $this->argument;
			if ($this->operator === '~' || $this->operator === '!~') {
				$args[$key] = str_replace(array('=', '_', '%', '*', '?'), array('==', '=_', '=%', '%', '_'), $args[$key]);
				$addendum = " ESCAPE '='";
			}
		}

		$op = $this->operator;
		if ($this->operator == '~') {
			$op = 'LIKE';
		} elseif ($this->operator == '!~') {
			$op = 'NOT LIKE';
		}

		return $this->column . ' ' . $op . ' :' . $key . $addendum;
	}

	/* parse a query into an array of filters */
	public static function parseQuery($query)
	{
		$operators = ['<=', '>=', '!=', '!~', '=', '~', '<', '>'];
		$filters = [];
		if (empty($query))
			return $filters;
		foreach (explode(self::DELIMITER, $query) as $q) {
			$q = trim($q);
			if (empty($q))
				continue;
			// Special case: User pasted UUID, turn into filter
			if (preg_match('/^\w{8}-\w{4}-\w{4}-\w{4}-\w{12}$/', $q)) {
				$filters[] = new Filter('machineuuid', '=', $q);
				continue;
			}
			// Special case: User pasted IP, turn into filter
			if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $q)) {
				$filters[] = new Filter('clientip', '=', $q);
				continue;
			}
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
				error_log("couldn't find operator in segment " . $q);
				/* TODO */
				continue;
			}
			$lhs = trim(substr($q, 0, $pos));
			$rhs = trim(substr($q, $pos + strlen($operator)));

			if ($lhs === 'gbram') {
				$filters[] = new RamGbFilter($operator, $rhs);
			} elseif ($lhs === 'runtime') {
				$filters[] = new RuntimeFilter($operator, $rhs);
			} elseif ($lhs === 'state') {
				$filters[] = new StateFilter($operator, $rhs);
			} elseif ($lhs === 'hddgb') {
				$filters[] = new Id44Filter($operator, $rhs);
			} elseif ($lhs === 'location') {
				$filters[] = new LocationFilter($operator, $rhs);
			} elseif ($lhs === 'subnet') {
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
		$lower = floor(Page_Statistics::findBestValue($SIZE_RAM, (int)$this->argument, false) * 1024 - 100);
		$upper = ceil(Page_Statistics::findBestValue($SIZE_RAM, (int)$this->argument, true) * 1024 + 100);
		if ($this->operator == '=') {
			return " mbram BETWEEN $lower AND $upper";
		} elseif ($this->operator == '<') {
			return " mbram < $lower";
		} elseif ($this->operator == '<=') {
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

class RuntimeFilter extends Filter
{
	public function __construct($operator, $argument)
	{
		parent::__construct('lastboot', $operator, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
		global $SIZE_RAM;
		$upper = time() - (int)$this->argument * 3600;
		$lower = $upper - 3600;
		$common = "state IN ('OCCUPIED', 'IDLE', 'STANDBY') AND";
		if ($this->operator == '=') {
			return "$common ({$this->column} BETWEEN $lower AND $upper)";
		} elseif ($this->operator == '<') {
			return "$common {$this->column} > $upper";
		} elseif ($this->operator == '<=') {
			return "$common {$this->column} > $lower";
		} elseif ($this->operator == '>') {
			return "$common {$this->column} < $lower";
		} elseif ($this->operator == '>=') {
			return "$common {$this->column} < $upper";
		} elseif ($this->operator == '!=') {
			return "$common ({$this->column} < $lower OR {$this->column} > $upper)";
		} else {
			error_log("unimplemented operator in RuntimeFilter: $this->operator");
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
		parent::__construct(null, $operator, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
		$map = [ 'on' => ['IDLE', 'OCCUPIED'], 'off' => ['OFFLINE'], 'idle' => ['IDLE'], 'occupied' => ['OCCUPIED'], 'standby' => ['STANDBY'] ];
		$neg = $this->operator == '!=' ? 'NOT ' : '';
		if (array_key_exists($this->argument, $map)) {
			global $unique_key;
			$key = $this->column . '_arg' . ($unique_key++);
			$args[$key] = $map[$this->argument];
			return " machine.state $neg IN ( :$key ) ";
		} else {
			Message::addError('invalid-filter-argument', 'state', $this->argument);
			return ' 1';
		}
	}
}

class LocationFilter extends Filter
{
	public function __construct($operator, $argument)
	{
		parent::__construct('locationid', $operator, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
		$recursive = (substr($this->operator, -1) === '~');
		$this->operator = str_replace('~', '=', $this->operator);

		settype($this->argument, 'int');
		$neg = $this->operator === '=' ? '' : 'NOT';
		if ($this->argument === 0) {
			return "machine.locationid IS $neg NULL";
		} else {
			global $unique_key;
			$key = $this->column . '_arg' . ($unique_key++);
			if ($recursive) {
				$args[$key] = array_keys(Location::getRecursiveFlat($this->argument));
			} else {
				$args[$key] = $this->argument;
			}
			return "machine.locationid $neg IN (:$key)";
		}
	}
}

class SubnetFilter extends Filter
{
	public function __construct($operator, $argument)
	{
		parent::__construct(null, $operator, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
		$argument = preg_replace('/[^0-9\.:]/', '', $this->argument);
		return " clientip LIKE '$argument%'";
	}
}

class IsClientFilter extends Filter
{
	public function __construct($argument)
	{
		parent::__construct(null, null, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
		if ($this->argument) {
			$joins[] = ' LEFT JOIN runmode USING (machineuuid)';
			return "(runmode.isclient <> 0 OR runmode.isclient IS NULL)";
		}
		$joins[] = ' INNER JOIN runmode USING (machineuuid)';
		return "runmode.isclient = 0";
	}
}
