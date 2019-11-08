<?php

/* base class with rudimentary SQL generation abilities.
 * WARNING: argument is escaped, but $column and $operator are passed unfiltered into SQL */

class StatisticsFilter
{
	/**
	 * Delimiter for js_selectize filters
	 */
	const DELIMITER = '~,~';

	const SIZE_ID44 = array(0, 8, 16, 24, 30, 40, 50, 60, 80, 100, 120, 150, 180, 250, 300, 400, 500, 1000, 2000, 4000);
	const SIZE_RAM = array(1, 2, 3, 4, 6, 8, 10, 12, 16, 24, 32, 48, 64, 96, 128, 192, 256, 320, 480, 512, 768, 1024);

	public $column;
	public $operator;
	public $argument;

	private static $keyCounter = 0;

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

	public static function getNewKey($colname)
	{
		return $colname . '_' . (self::$keyCounter++);
	}

	public function __construct($column, $operator, $argument = null)
	{
		$this->column = trim($column);
		$this->operator = trim($operator);
		$this->argument = is_array($argument) ? $argument : trim($argument);
	}

	/* returns a where clause and adds needed operators to the passed array */
	public function whereClause(&$args, &$joins)
	{
		$key = StatisticsFilter::getNewKey($this->column);
		$addendum = '';

		/* check if we have to do some parsing*/
		if (self::$columns[$this->column]['type'] === 'date') {
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

		return 'm.' . $this->column . ' ' . $op . ' :' . $key . $addendum;
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
				$filters[] = new StatisticsFilter('machineuuid', '=', $q);
				continue;
			}
			// Special case: User pasted IP, turn into filter
			if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $q)) {
				$filters[] = new StatisticsFilter('clientip', '=', $q);
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
				$filters[] = new RamGbStatisticsFilter($operator, $rhs);
			} elseif ($lhs === 'runtime') {
				$filters[] = new RuntimeStatisticsFilter($operator, $rhs);
			} elseif ($lhs === 'state') {
				$filters[] = new StateStatisticsFilter($operator, $rhs);
			} elseif ($lhs === 'hddgb') {
				$filters[] = new Id44StatisticsFilter($operator, $rhs);
			} elseif ($lhs === 'location') {
				$filters[] = new LocationStatisticsFilter($operator, $rhs);
			} elseif ($lhs === 'subnet') {
				$filters[] = new SubnetStatisticsFilter($operator, $rhs);
			} else {
				if (array_key_exists($lhs, self::$columns) && self::$columns[$lhs]['column']) {
					$filters[] = new StatisticsFilter($lhs, $operator, $rhs);
				} else {
					Message::addError('invalid-filter-key', $lhs);
				}
			}
		}

		return $filters;
	}

	/**
	 * @param \StatisticsFilterSet $filterSet
	 */
	public static function renderFilterBox($show, $filterSet, $query)
	{
		$data = array(
			'show' => $show,
			'query' => $query,
			'delimiter' => StatisticsFilter::DELIMITER,
			'sortDirection' => $filterSet->getSortDirection(),
			'sortColumn' => $filterSet->getSortColumn(),
			'columns' => json_encode(StatisticsFilter::$columns),
		);

		if ($show === 'list') {
			$data['listButtonClass'] = 'active';
			$data['statButtonClass'] = '';
		} else {
			$data['listButtonClass'] = '';
			$data['statButtonClass'] = 'active';
		}


		$locsFlat = array();
		if (Module::isAvailable('locations')) {
			$allowed = $filterSet->getAllowedLocations();
			foreach (Location::getLocations() as $loc) {
				$locsFlat['L' . $loc['locationid']] = array(
					'pad' => $loc['locationpad'],
					'name' => $loc['locationname'],
					'disabled' => $allowed !== false && !in_array($loc['locationid'], $allowed),
				);
			}
		}

		Permission::addGlobalTags($data['perms'], null, ['view.summary', 'view.list']);
		$data['locations'] = json_encode($locsFlat);
		Render::addTemplate('filterbox', $data);
	}

	private static $query = false;

	public static function getQuery()
	{
		if (self::$query === false) {
			self::$query = Request::any('filters', false, 'string');
			if (self::$query === false) {
				self::$query = 'lastseen > ' . gmdate('Y-m-d', strtotime('-30 day'));
			}
		}
		return self::$query;
	}

	/*
	 * Simple filters that map directly to DB columns
	 */

	const OP_ORDINAL = ['!=', '<=', '>=', '=', '<', '>'];
	const OP_STRCMP = ['!~', '~', '=', '!='];
	const OP_NOMINAL = ['!=', '='];
	public static $columns;

	/**
	 * Do this here instead of const since we need to check for available modules while building array.
	 */
	public static function initConstants()
	{

		self::$columns = [
			'machineuuid' => [
				'op' => self::OP_NOMINAL,
				'type' => 'string',
				'column' => true,
			],
			'macaddr' => [
				'op' => self::OP_NOMINAL,
				'type' => 'string',
				'column' => true,
			],
			'firstseen' => [
				'op' => self::OP_ORDINAL,
				'type' => 'date',
				'column' => true,
			],
			'lastseen' => [
				'op' => self::OP_ORDINAL,
				'type' => 'date',
				'column' => true,
			],
			'logintime' => [
				'op' => self::OP_ORDINAL,
				'type' => 'date',
				'column' => true,
			],
			'realcores' => [
				'op' => self::OP_ORDINAL,
				'type' => 'int',
				'column' => true,
			],
			'systemmodel' => [
				'op' => self::OP_STRCMP,
				'type' => 'string',
				'column' => true,
			],
			'cpumodel' => [
				'op' => self::OP_STRCMP,
				'type' => 'string',
				'column' => true,
			],
			'hddgb' => [
				'op' => self::OP_ORDINAL,
				'type' => 'int',
				'column' => false,
				'map_sort' => 'id44mb'
			],
			'gbram' => [
				'op' => self::OP_ORDINAL,
				'type' => 'int',
				'map_sort' => 'mbram',
				'column' => false,
			],
			'kvmstate' => [
				'op' => self::OP_NOMINAL,
				'type' => 'enum',
				'column' => true,
				'values' => ['ENABLED', 'DISABLED', 'UNSUPPORTED']
			],
			'badsectors' => [
				'op' => self::OP_ORDINAL,
				'type' => 'int',
				'column' => true
			],
			'clientip' => [
				'op' => self::OP_NOMINAL,
				'type' => 'string',
				'column' => true
			],
			'hostname' => [
				'op' => self::OP_STRCMP,
				'type' => 'string',
				'column' => true
			],
			'subnet' => [
				'op' => self::OP_NOMINAL,
				'type' => 'string',
				'column' => false
			],
			'currentuser' => [
				'op' => self::OP_NOMINAL,
				'type' => 'string',
				'column' => true
			],
			'state' => [
				'op' => self::OP_NOMINAL,
				'type' => 'enum',
				'column' => true,
				'values' => ['occupied', 'on', 'off', 'idle', 'standby']
			],
			'live_swapfree' => [
				'op' => self::OP_ORDINAL,
				'type' => 'int',
				'column' => true
			],
			'live_memfree' => [
				'op' => self::OP_ORDINAL,
				'type' => 'int',
				'column' => true
			],
			'live_tmpfree' => [
				'op' => self::OP_ORDINAL,
				'type' => 'int',
				'column' => true
			],
		];
		if (Module::isAvailable('locations')) {
			self::$columns['location'] = [
				'op' => self::OP_STRCMP,
				'type' => 'enum',
				'column' => false,
				'values' => array_keys(Location::getLocationsAssoc()),
			];
		}
	}

}

class RamGbStatisticsFilter extends StatisticsFilter
{
	public function __construct($operator, $argument)
	{
		parent::__construct('mbram', $operator, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
		$lower = floor(StatisticsFilter::findBestValue(StatisticsFilter::SIZE_RAM, (int)$this->argument, false) * 1024 - 100);
		$upper = ceil(StatisticsFilter::findBestValue(StatisticsFilter::SIZE_RAM, (int)$this->argument, true) * 1024 + 100);
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

class RuntimeStatisticsFilter extends StatisticsFilter
{
	public function __construct($operator, $argument)
	{
		parent::__construct('lastboot', $operator, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
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

class Id44StatisticsFilter extends StatisticsFilter
{
	public function __construct($operator, $argument)
	{
		parent::__construct('id44mb', $operator, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
		if ($this->operator === '=' || $this->operator === '!=') {
			$lower = floor(StatisticsFilter::findBestValue(StatisticsFilter::SIZE_ID44, $this->argument, false) * 1024 - 100);
			$upper = ceil(StatisticsFilter::findBestValue(StatisticsFilter::SIZE_ID44, $this->argument, true) * 1024 + 100);
		} else {
			$lower = $upper = round($this->argument * 1024);
		}

		if ($this->operator === '=') {
			return " id44mb BETWEEN $lower AND $upper";
		} elseif ($this->operator === '!=') {
			return " id44mb < $lower OR id44mb > $upper";
		} elseif ($this->operator === '<=') {
			return " id44mb <= $upper";
		} elseif ($this->operator === '>=') {
			return " id44mb >= $lower";
		} elseif ($this->operator === '<') {
			return " id44mb < $lower";
		} elseif ($this->operator === '>') {
			return " id44mb > $upper";
		} else {
			error_log("unimplemented operator in Id44Filter: $this->operator");

			return ' 1';
		}
	}
}

class StateStatisticsFilter extends StatisticsFilter
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
			$key = StatisticsFilter::getNewKey($this->column);
			$args[$key] = $map[$this->argument];
			return " m.state $neg IN ( :$key ) ";
		} else {
			Message::addError('invalid-filter-argument', 'state', $this->argument);
			return ' 1';
		}
	}
}

class LocationStatisticsFilter extends StatisticsFilter
{
	public function __construct($operator, $argument)
	{
		parent::__construct('locationid', $operator, $argument);
	}

	public function whereClause(&$args, &$joins)
	{
		$recursive = (substr($this->operator, -1) === '~');
		$this->operator = str_replace('~', '=', $this->operator);

		if (is_array($this->argument)) {
			if ($recursive)
				Util::traceError('Cannot use ~ operator for location with array');
		} else {
			settype($this->argument, 'int');
		}
		$neg = $this->operator === '=' ? '' : 'NOT';
		if ($this->argument === 0) {
			return "m.locationid IS $neg NULL";
		} else {
			$key = StatisticsFilter::getNewKey($this->column);
			if ($recursive) {
				$args[$key] = array_keys(Location::getRecursiveFlat($this->argument));
			} else {
				$args[$key] = $this->argument;
			}
			return "m.locationid $neg IN (:$key)";
		}
	}
}

class SubnetStatisticsFilter extends StatisticsFilter
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

class IsClientStatisticsFilter extends StatisticsFilter
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

StatisticsFilter::initConstants();