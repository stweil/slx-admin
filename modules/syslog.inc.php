<?php

User::load();

if (!User::isLoggedIn()) {
	Util::redirect('?do=main');
}

function render_module()
{
	Render::setTitle('Client Log');
	
	$filter = '';
	$not = '';
	if (isset($_POST['filter'])) $filter = $_POST['filter'];
	if (!empty($filter)) {
		$parts = explode(' ', $filter);
		$opt = array();
		foreach ($parts as $part) {
			$part = preg_replace('/[^a-z0-9_\-]/', '', trim($part));
			if (empty($part) || in_array($part, $opt)) continue;
			$opt[] = "'$part'";
		}
		if (isset($_POST['not'])) $not = 'NOT';
		if (!empty($opt)) $opt = ' WHERE logtypeid ' . $not . ' IN (' . implode(', ', $opt) . ')';
	}
	if (!isset($opt) || empty($opt)) $opt = '';

	$today = date('d.m.Y');
	$yesterday = date('d.m.Y', time() - 86400);
	$lines = array();
	$res = Database::simpleQuery("SELECT logid, dateline, logtypeid, clientip, description, extra FROM clientlog $opt ORDER BY logid DESC LIMIT 200");
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$day = date('d.m.Y', $row['dateline']);
		// TODO: No output strings in source files!
		if ($day === $today) {
			$day = 'Heute ';
		} elseif ($day === $yesterday) {
			$day = 'Gestern ';
		}
		$row['date'] = $day . date('H:i', $row['dateline']);
		$lines[] = $row;
	}
	
	Render::addTemplate('page-syslog', array(
		'token'    => Session::get('token'),
		'filter'   => $filter,
		'not'      => $not,
		'list'     => $lines
	));
}

