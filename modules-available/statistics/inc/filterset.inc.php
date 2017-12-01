<?php

class FilterSet
{
	/**
	 * @var \Filter[]
	 */
	private $filters;
	private $sortDirection;
	private $sortColumn;

	public function __construct($filters)
	{
		$this->filters = $filters;
	}

	public function setSort($col, $direction)
	{
		$this->sortDirection = $direction === 'DESC' ? 'DESC' : 'ASC';

		if (is_string($col) && array_key_exists($col, Page_Statistics::$columns)) {
			$this->sortColumn = $col;
		} else {
			/* default sorting column is clientip */
			$this->sortColumn = 'clientip';
		}

	}

	public function makeFragments(&$where, &$join, &$sort, &$args)
	{
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
		$isMapped = array_key_exists('map_sort', Page_Statistics::$columns[$col]);
		$concreteCol = ($isMapped ? Page_Statistics::$columns[$col]['map_sort'] : $col) ;

		if ($concreteCol === 'clientip') {
			$concreteCol = "INET_ATON(clientip)";
		}

		$sort = " ORDER BY " . $concreteCol . " " . $this->sortDirection
			. ", machineuuid ASC";
	}
	
	public function isNoId44Filter()
	{
		foreach ($this->filters as $filter) {
			if (get_class($filter) === 'Id44Filter' && $filter->argument == 0) {
				return true;
			}
		}
		return false;
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
		if (Module::get('runmode') === false)
			return;
		// Runmode module exists, add filter
		$this->filters[] = new IsClientFilter(true);
	}

}
