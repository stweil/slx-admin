<?php

class StatisticsFilterSet
{
	/**
	 * @var \StatisticsFilter[]
	 */
	private $filters;
	private $sortDirection;
	private $sortColumn;

	private $cache = false;

	public function __construct($filters)
	{
		$this->filters = $filters;
	}

	public function setSort($col, $direction)
	{
		$direction = ($direction === 'DESC' ? 'DESC' : 'ASC');

		if (!is_string($col) || !array_key_exists($col, StatisticsFilter::$columns)) {
			/* default sorting column is clientip */
			$col = 'clientip';
		}
		if ($col === $this->sortColumn && $direction === $this->sortDirection)
			return;
		$this->cache = false;
		$this->sortDirection =  $direction;
		$this->sortColumn = $col;
	}

	public function makeFragments(&$where, &$join, &$sort, &$args)
	{
		if ($this->cache !== false) {
			$where = $this->cache['where'];
			$join = $this->cache['join'];
			$sort = $this->cache['sort'];
			$args = $this->cache['args'];
			return;
		}
		/* generate where clause & arguments */
		$where = '';
		$joins = [];
		$sort = "";
		$args = [];
		if (empty($this->filters)) {
			$where = ' 1 ';
		} else {
			foreach ($this->filters as $filter) {
				$sep = ($where != '' ? ' AND ' : '');
				$where .= $sep . $filter->whereClause($args, $joins);
			}
		}
		$join = implode(' ', array_unique($joins));

		$col = $this->sortColumn;
		$isMapped = array_key_exists('map_sort', StatisticsFilter::$columns[$col]);
		$concreteCol = ($isMapped ? StatisticsFilter::$columns[$col]['map_sort'] : $col) ;

		if ($concreteCol === 'clientip') {
			$concreteCol = "INET_ATON(clientip)";
		}

		$sort = " ORDER BY " . $concreteCol . " " . $this->sortDirection
			. ", machineuuid ASC";
		$this->cache = compact('where', 'join', 'sort', 'args');
	}
	
	public function isNoId44Filter()
	{
		$filter = $this->hasFilter('Id44Filter');
		return $filter !== false && $filter->argument == 0;
	}

	public function getSortDirection()
	{
		return $this->sortDirection;
	}

	public function getSortColumn()
	{
		return $this->sortColumn;
	}

	public function filterNonClients()
	{
		if (Module::get('runmode') === false || $this->hasFilter('IsClientFilter') !== false)
			return;
		$this->cache = false;
		// Runmode module exists, add filter
		$this->filters[] = new IsClientStatisticsFilter(true);
	}

	/**
	 * @param string $type filter type (class name)
	 * @return false|StatisticsFilter The filter, false if not found
	 */
	public function hasFilter($type)
	{
		foreach ($this->filters as $filter) {
			if (get_class($filter) === $type) {
				return $filter;
			}
		}
		return false;
	}

	/**
	 * Add a location filter based on the allowed permissions for the given permission.
	 * Returns false if the user doesn't have the given permission for any location.
	 *
	 * @param string $permission permission to use
	 * @return bool false if no permission for any location, true otherwise
	 */
	public function setAllowedLocationsFromPermission($permission)
	{
		$locs = User::getAllowedLocations($permission);
		if (empty($locs))
			return false;
		if (in_array(0, $locs)) {
			if (!isset($this->filters['permissions']))
				return true;
			unset($this->filters['permissions']);
		} else {
			$this->filters['permissions'] = new LocationStatisticsFilter('=', $locs);
		}
		$this->cache = false;
		return true;
	}

	/**
	 * @return false|array
	 */
	public function getAllowedLocations()
	{
		if (isset($this->filters['permissions']->argument) && is_array($this->filters['permissions']->argument))
			return $this->filters['permissions']->argument;
		return false;
	}

}
