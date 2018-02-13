<?php

class Page_SysLog extends Page
{

	const PROP_ANON_DAYS = 'syslog.anon-days'; // Copy in cronjob


	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		if (($days = Request::post('anondays', false, 'int')) !== false) {
			User::assertPermission('configure-anonymization', NULL,'?do=syslog');
			if ($days < 0 || $days > 180) {
				Message::addError('anon-days-out-of-range', $days);
			} else {
				Property::set(self::PROP_ANON_DAYS, $days);
				Message::addSuccess('anon-days-saved');
			}
			Util::redirect('?do=syslog');
		}
	}

	protected function doRender()
	{
		$data = ['anondays' => Property::get(self::PROP_ANON_DAYS, 0)];
		Permission::addGlobalTags($data['perms'], NULL, ['configure-anonymization']);
		Render::addTemplate("heading", $data);

		if (!User::hasPermission("view")) {
			Message::addError('main.no-permission');
			return;
		}

		$cutoff = strtotime('-1 month');
		$res = Database::simpleQuery("SELECT logtypeid, Count(*) AS counter FROM clientlog WHERE dateline > $cutoff GROUP BY logtypeid ORDER BY counter ASC");
		$types = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$types[$row['logtypeid']] = $row;
		}
		if (Request::get('filter') !== false) {
			$filter = Request::get('filter');
			$not = Request::get('not') ? 'NOT' : '';
		} elseif (Request::post('filter') !== false) {
			$filter = Request::post('filter');
			$not = Request::post('not') ? 'NOT' : '';

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
				if (!isset($types[$filterItem])) {
					$types[$filterItem] = ['logtypeid' => $filterItem, 'counter' => ''];
				}
			}
			if (!empty($whereClause)) $whereClause = ' WHERE logtypeid ' . $not . ' IN (' . implode(', ', $whereClause) . ')';
		}
		if (!isset($whereClause) || empty($whereClause)) $whereClause = '';
		if (Request::get('machineuuid')) {
			if (empty($whereClause))
				$whereClause .= ' WHERE ';
			else
				$whereClause .= ' AND ';

			$whereClause .= "machineuuid='" . preg_replace('/[^0-9a-zA-Z\-]/', '', Request::get('machineuuid', '', 'string')) . "'";
		}

		$allowedLocations = User::getAllowedLocations("view");
		$joinClause = "";
		if (!in_array(0, $allowedLocations)) {
			$joinClause = "INNER JOIN machine USING (machineuuid)";
			if (empty($whereClause))
				$whereClause .= ' WHERE ';
			else
				$whereClause .= ' AND ';

			$whereClause .= 'locationid IN (:allowedLocations)';
		}

		$lines = array();
		$paginate = new Paginate("SELECT logid, dateline, logtypeid, clientlog.clientip, clientlog.machineuuid, description, extra FROM clientlog $joinClause $whereClause ORDER BY logid DESC", 50);
		$res = $paginate->exec(array("allowedLocations" => $allowedLocations));
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['date'] = Util::prettyTime($row['dateline']);
			$row['icon'] = $this->eventToIconName($row['logtypeid']);
			$lines[] = $row;
		}

		$paginate->render('page-syslog', array(
			'filter'   => $filter,
			'not'      => $not,
			'list'     => $lines,
			'types'    => json_encode(array_values($types)),
			'machineuuid' => Request::get('machineuuid'),
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
