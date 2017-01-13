<?php


class Page_Statistics_Reporting extends Page
{

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
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		// timespan you want to see = Days selected * seconds per Day (86400)
		// default = 14 days
		GetData::$from = strtotime("- ".(Request::get('cutoff', 14, 'int') - 1)." days 00:00:00");
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
