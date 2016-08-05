<?php

class Page_Roomplanner extends Page
{
	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

	}

	protected function doRender()
	{
		Render::addTemplate('page', []);
	}

	protected function doAjax()
	{
		$action = Request::get('action', null, 'string');

		if ($action === 'getmachines') {
			$query = Request::get('query', null, 'string');

			/* the query could be anything: UUID, IP or macaddr */
//			$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
//				. ', MATCH (machineuuid, macaddr, clientip, hostname) AGAINST (:query) AS relevance '
//				. 'FROM machine '
//				. 'WHERE MATCH (machineuuid, macaddr, clientip, hostname) AGAINST (:query) '
//				. 'ORDER BY relevance DESC '
//				. 'LIMIT 5'
//				, ['query' => $query]);
//
			$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
				. 'FROM machine '
				. "WHERE machineuuid LIKE :query "
				.   " OR macaddr  	 LIKE :query "
				.   " OR clientip    LIKE :query "
				.   " OR hostname	 LIKE :query ", ['query' => "%$query%"]);

			$returnObject = ['machines' => []];

			while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
				$returnObject['machines'][] = $row;
			}
			echo json_encode($returnObject, JSON_PRETTY_PRINT);

		}
	}
}
