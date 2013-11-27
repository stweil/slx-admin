<?php

User::load();

if (!User::isLoggedIn()) {
	Util::redirect('?do=main');
}

function render_module()
{
	Render::setTitle('Client Log');

	$lines = array();
	$res = Database::simpleQuery('SELECT logid, dateline, logtypeid, clientip, description, extra FROM clientlog ORDER BY logid DESC LIMIT 200');
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$row['date'] = date('d.m.Y H:i', $row['dateline']);
		$lines[] = $row;
	}
	
	Render::addTemplate('page-syslog', array('token' => Session::get('token'), 'list' => $lines));
}

