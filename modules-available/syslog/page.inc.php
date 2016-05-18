<?php

class Page_SysLog extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}
	}

	protected function doRender()
	{
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
		if (Request::get('ip')) {
			if (empty($whereClause))
				$whereClause .= ' WHERE ';
			else
				$whereClause .= ' AND ';
			$whereClause .= "clientip LIKE '" . preg_replace('/[^0-9\.\:]/', '', Request::get('ip')) . "%'";
		}

		$today = date('d.m.Y');
		$yesterday = date('d.m.Y', time() - 86400);
		$lines = array();
		$paginate = new Paginate("SELECT logid, dateline, logtypeid, clientip, description, extra FROM clientlog $whereClause ORDER BY logid DESC", 50);
		$res = $paginate->exec();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$day = date('d.m.Y', $row['dateline']);
			if ($day === $today) {
				$day = Dictionary::translate('lang_today');
			} elseif ($day === $yesterday) {
				$day = Dictionary::translate('lang_yesterday');
			}
			$row['date'] = $day . date(' H:i', $row['dateline']);
			$row['icon'] = $this->eventToIconName($row['logtypeid']);
			$lines[] = $row;
		}

		$paginate->render('page-syslog', array(
			'filter'   => $filter,
			'not'      => $not,
			'list'     => $lines
		));
	}
	
	private function eventToIconName($event)
	{
		switch ($event) {
		case 'session-open':
			return 'glyphicon-log-in';
		case 'session-close':
			return 'glyphicon-log-out';
		case 'partition-swap':
			return 'glyphicon-info-sign';
		case 'partition-temp':
		case 'smartctl-realloc':
			return 'glyphicon-exclamation-sign';
		default:
			return 'glyphicon-minus';
		}
	}

}
