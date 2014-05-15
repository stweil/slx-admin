<?php

require_once('inc/paginate.inc.php');

User::load();

if (!User::isLoggedIn()) {
	Util::redirect('?do=main');
}

function render_module()
{
	Render::setTitle('Client Log');
	
	if (isset($_GET['filter'])) {
		$filter = $_GET['filter'];
		$not = isset($_GET['not']) ? 'NOT' : '';
	} elseif (isset($_POST['filter'])) {
		$filter = $_POST['filter'];
		$not = isset($_POST['not']) ? 'NOT' : '';
		Session::set('log_filter', $filter);
		Session::set('log_not', $not);
		Session::save();
	} else {
		$filter = Session::get('log_filter');
		$not = Session::get('log_not') ? 'NOT' : '';
	}
	if (!empty($filter)) {
		$filterList = explode(',', $filter);
		$whereClause = array();
		foreach ($filterList as $filterItem) {
			$filterItem = preg_replace('/[^a-z0-9_\-]/', '', trim($filterItem));
			if (empty($filterItem) || in_array($filterItem, $whereClause)) continue;
			$whereClause[] = "'$filterItem'";
		}
		if (!empty($whereClause)) $whereClause = ' WHERE logtypeid ' . $not . ' IN (' . implode(', ', $whereClause) . ')';
	}
	if (!isset($whereClause) || empty($whereClause)) $whereClause = '';

	$today = date('d.m.Y');
	$yesterday = date('d.m.Y', time() - 86400);
	$lines = array();
	$paginate = new Paginate("SELECT logid, dateline, logtypeid, clientip, description, extra FROM clientlog $whereClause ORDER BY logid DESC", 50);
	$res = $paginate->exec();
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$day = date('d.m.Y', $row['dateline']);
		// TODO: No output strings in source files!
		if ($day === $today) {
			$day = 'Heute';
		} elseif ($day === $yesterday) {
			$day = 'Gestern';
		}
		$row['date'] = $day . date(' H:i', $row['dateline']);
		$lines[] = $row;
	}
	
	$paginate->render('page-syslog', array(
		'token'    => Session::get('token'),
		'filter'   => $filter,
		'not'      => $not,
		'list'     => $lines
	));
}

