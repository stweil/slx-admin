<?php

class Page_EventLog extends Page
{

	protected function doPreprocess()
	{
		User::load();
		User::assertPermission('view');
		User::setLastSeenEvent(Property::getLastWarningId());
	}

	protected function doRender()
	{
		Render::addTemplate("heading");
		$lines = array();
		$paginate = new Paginate("SELECT logid, dateline, logtypeid, description, extra FROM eventlog ORDER BY logid DESC", 50);
		$res = $paginate->exec();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['date'] = Util::prettyTime($row['dateline']);
			$row['icon'] = $this->typeToIcon($row['logtypeid']);
			$row['color'] = $this->typeToColor($row['logtypeid']);
			$lines[] = $row;
		}

		$paginate->render('_page', array(
			'list' => $lines
		));
	}

	private function typeToIcon($type)
	{
		switch ($type) {
			case 'info':
				return 'ok';
			case 'warning':
				return 'exclamation-sign';
			case 'failure':
				return 'remove';
			default:
				return 'question-sign';
		}
	}

	private function typeToColor($type)
	{
		switch ($type) {
			case 'info':
				return '';
			case 'warning':
				return 'orange';
			case 'failure':
				return 'red';
			default:
				return '';
		}
	}

}
