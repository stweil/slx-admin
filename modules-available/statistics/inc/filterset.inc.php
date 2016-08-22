<?php

class FilterSet
{
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
		$join = implode('', array_unique($joins));

		$col = $this->sortColumn;
		$isMapped = array_key_exists('map_sort', Page_Statistics::$columns[$col]);
		$sort = " ORDER BY " . ($isMapped ? Page_Statistics::$columns[$col]['map_sort'] : $col) . " " . $this->sortDirection
			. ", machineuuid ASC";
	}

	public function getSortDirection()
	{
		return $this->sortDirection;
	}

	public function getSortColumn()
	{
		return $this->sortColumn;
	}
}
