<?php

class Paginate
{
	private $query;
	private $currentPage;
	private $perPage;
	private $url;
	private $totalRows = false;

	/**
	 * @query - The query that will return lines to show
	 * @currentPage - 0based index of currently viewed page
	 * @perPage - Number of items to show per page
	 * @url - URL of current wegpage
	 */
	public function __construct($query, $perPage, $url = false)
	{
		$this->currentPage = (isset($_GET['page']) ? (int)$_GET['page'] : 0);
		$this->perPage = (int)$perPage;
		if ($this->currentPage < 0) {
			Util::traceError('Current page < 0');
		}
		if ($this->perPage < 1) {
			Util::traceError('Per page < 1');
		}
		// Query
		if (!preg_match('/\s*SELECT\s/is', $query)) {
			Util::traceError('Query has to start with SELECT!');
		}
		// XXX: MySQL only
		if (preg_match('/^mysql/i', CONFIG_SQL_DSN)) {
			// Sanity: Check for LIMIT specification at the end
			if (preg_match('/LIMIT\s+(\d+|\:\w+|\?)\s*,\s*(\d+|\:\w+|\?)(\s|;)*(\-\-.*)?$/is', $query)) {
				Util::traceError("You cannot pass a query containing a LIMIT to the Paginator class!");
			}
			// Sanity: no comment or semi-colon at end (sloppy, might lead to false negatives)
			if (preg_match('/(\-\-|;)(\s|[^\'"`])*$/is', $query)) {
				Util::traceError("Your query must not end in a comment or semi-colon!");
			}
			$query .= ' LIMIT ' . ($this->currentPage * $this->perPage) . ', ' . $this->perPage;
			// Use SQL_CALC_FOUND_ROWS
			if (!preg_match('/^\s*SELECT\s+SQL_CALC_FOUND_ROWS/is', $query)) {
				$query = preg_replace('/^\s*SELECT/is', 'SELECT SQL_CALC_FOUND_ROWS ', $query);
			}
		} else {
			Util::traceError('Unsupported database engine');
		}
		// Mangle URL
		if ($url === false) $url = $_SERVER['REQUEST_URI'];
		if (strpos($url, '?') === false) {
			$url .= '?';
		} else {
			$url = preg_replace('/(\?|&)&*page=[^&]*(&+|$)/i', '$1', $url);
			if (substr($url, -1) !== '&') $url .= '&';
		}
		//
		$this->query =$query;
		$this->url = $url;
	}

	/**
	 * Execute the query, returning the PDO query object
	 */
	public function exec($args = array())
	{
		$args[':limit_start'] = $this->currentPage;
		$args[':limit_count'] = $this->perPage;
		$retval = Database::simpleQuery($this->query, $args);
		$res = Database::queryFirst('SELECT FOUND_ROWS() AS rowcount');
		$this->totalRows = (int)$res['rowcount'];
		return $retval;
	}

	public function render($template, $data)
	{
		if ($this->totalRows == 0) {
			// Shortcut for no content
			Render::addTemplate($template, $data);
			return;
		}
		// The real thing
		$pages = array();
		$pageCount = floor(($this->totalRows - 1) / $this->perPage) + 1;
		$skip = false;
		for ($i = 0; $i < $pageCount; ++$i) {
			if (($i > 0 && $i < $this->currentPage - 3) || ($i > $this->currentPage + 3 && $i < $pageCount - 1)) {
				if (!$skip) {
					$skip = true;
					$pages[] = array(
						'text'     => false,
						'current'  => false
					);
				}
				continue;
			}
			$skip = false;
			$pages[] = array(
				'text'     => $i + 1,
				'page'     => $i,
				'current'  => $i == $this->currentPage,
			);
		}
		$pages = Render::parse('pagenav', array(
			'url'       => $this->url,
			'pages'     => $pages,
		));
		$data['page'] = $this->currentPage;
		$data['pagenav'] = $pages;
		Render::addTemplate($template, $data);
	}

}

