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

			$data = GetData::total(GETDATA_PRINTABLE);
			$data['perLocation'] = GetData::perLocation(GETDATA_PRINTABLE);
			$data['perClient'] = GetData::perClient(GETDATA_PRINTABLE);
			$data['perUser'] = GetData::perUser(GETDATA_PRINTABLE);
			$data['perVM'] = GetData::perVM(GETDATA_PRINTABLE);

			Render::addTemplate('columnChooser');
			Render::addTemplate('_page', $data);
		}
	}

	protected function doAjax()
	{
		$this->action = Request::any('action', false, 'string');
		if ($this->action === 'setReporting') {
			if (!User::isLoggedIn()) {
				die("No.");
			}
			$state = Request::post('reporting', false, 'string');
			if ($state === false) {
				die('Missing setting value.');
			}
			RemoteReport::setReportingEnabled($state);
		} elseif ($this->action === 'getReporting') {
			echo RemoteReport::isReportingEnabled() ? 'on' : '';
		} else {
			echo 'Invalid action.';
		}
	}

}
