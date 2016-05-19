<?php

/**
 * TODO: Why does this class exist?
 * There's already the Paginate class which works more efficient by using the LIMIT statement
 * for the query, and has more options. Consider refactoring the places where this class is
 * used (see syslog or eventlog for usage examples), then get rid of this one.
 */
class Pagination
{
	private $items;
	private $page;
	private $maxItems;

	public function __construct($par1, $par2)
	{
		$this->items = $par1;
		$this->page = $par2;

		$this->maxItems = 5;
	}

	public function getPagination()
	{
		$ret = array();
		$n = ceil(count($this->items) / $this->maxItems);
		for ($i = 1; $i <= $n; $i++) {
			$class = ($i == $this->page) ? 'active' : '';
			$ret[] = array(
				'class' => $class,
				'page' => $i
			);
		}
		return $ret;
	}

	public function getItems()
	{
		$ret = array();
		$first = ($this->page - 1) * $this->maxItems;
		for ($i = 0; $i < $this->maxItems; $i++) {
			if ($first + $i < count($this->items))
				$ret[] = $this->items[$first + $i];
		}
		return $ret;
	}
}