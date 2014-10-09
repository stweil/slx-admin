<?php

class Page_EventLog extends Page
{

	protected function doPreprocess()
	{
		User::load();
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}
		User::setLastSeenEvent(Property::getLastWarningId());
	}

	protected function doRender()
	{
		Render::setTitle(Dictionary::translate('lang_titleEventLog'));
		$today = date('d.m.Y');
		$yesterday = date('d.m.Y', time() - 86400);
		$lines = array();
		$paginate = new Paginate("SELECT logid, dateline, logtypeid, description, extra FROM eventlog ORDER BY logid DESC", 50);
		$res = $paginate->exec();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$day = date('d.m.Y', $row['dateline']);
			if ($day === $today) {
				$day = Dictionary::translate('today');
			} elseif ($day === $yesterday) {
				$day = Dictionary::translate('yesterday');
			}
			$row['date'] = $day . date(' H:i', $row['dateline']);
			$row['icon'] = $this->typeToIcon($row['logtypeid']);
			$lines[] = $row;
		}

		$paginate->render('eventlog/_page', array(
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
			case 'error':
				return 'remove';
			default:
				return 'question-sign';
		}
	}

}
