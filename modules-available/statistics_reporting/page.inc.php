<?php


class Page_Statistics_Reporting extends Page
{

	private $action = false;

	/**
	 * Called before any page rendering happens - early hook to check parameters etc.
	 */
	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main'); // does not return
		}

		$this->action = Request::any('action', 'show', 'string');
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		if ($this->action === 'show') {
			// timespan you want to see. default = last 7 days
			GetData::$from = strtotime("- " . (Request::get('cutoff', 14, 'int') - 1) . " days 00:00:00");
			GetData::$to = time();
			GetData::$lowerTimeBound = Request::get('lower', 0, 'int');
			GetData::$upperTimeBound = Request::get('upper', 24, 'int');

			$data = array_merge(GetData::total(), array('perLocation' => array(), 'perClient' => array(), 'perUser' => array(), 'perVM' => array()));
			$data['perLocation'] = GetData::perLocation();
			$data['perClient'] = GetData::perClient();
			$data['perUser'] = GetData::perUser();
			$data['perVM'] = GetData::perVM();

			Render::addTemplate('columnChooser');
			Render::addTemplate('_page', $data);
		}
	}

	protected function doAjax()
	{
		$this->action = Request::any('action', false, 'string');
		if ($this->action === 'setReporting') {
			Property::set("reportingStatus", Request::get('reporting', "on", 'string'));
		} elseif ($this->action === 'getReporting') {
			echo Property::get("reportingStatus", "on");
		}
	}
}
