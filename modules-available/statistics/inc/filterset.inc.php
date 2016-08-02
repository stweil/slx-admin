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

		if (array_key_exists($col, Page_Statistics::$columns)) {
			$isMapped = array_key_exists('map_sort', Page_Statistics::$columns[$col]);
			$this->sortColumn = $isMapped ? Page_Statistics::$columns[$col]['map_sort'] : $col;
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


		$sort = " ORDER BY " . $this->sortColumn . " " . $this->sortDirection;
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
