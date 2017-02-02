<?php


class Page_Statistics_Reporting extends Page
{

	private $action;
	private $type;

	// "Constants"
	private $days;

	/**
	 * @var array Names of columns that are being used by the various tables
	 */
	private $COLUMNS = array('col_lastlogout', 'col_laststart', 'col_location', 'col_longsessions', 'col_mediantime',
		'col_sessions', 'col_shortsessions', 'col_timeoffline', 'col_totaltime');

	/**
	 * @var array Names of the tables we can display
	 */
	private $TABLES = array('total', 'location', 'client', 'user', 'vm');

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
		$this->type = Request::get('type', 'total', 'string');
		$this->days = Request::get('cutoff', 7, 'int');
		$this->lower = Request::get('lower', 8, 'int');
		$this->upper = Request::get('upper', 20, 'int');

		if (!in_array($this->type, $this->TABLES)) {
			Message::addError('invalid-table-type', $this->type);
			$this->type = 'total';
		}
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		if ($this->action === 'show') {

			/*
			 * Leave these here for the translate module
			 * Dictionary::translate('col_lastlogout');
			 * Dictionary::translate('col_laststart');
			 * Dictionary::translate('col_location');
			 * Dictionary::translate('col_longsessions');
			 * Dictionary::translate('col_mediantime');
			 * Dictionary::translate('col_sessions');
			 * Dictionary::translate('col_shortsessions');
			 * Dictionary::translate('col_timeoffline');
			 * Dictionary::translate('col_totaltime');
			 * Dictionary::translate('table_total');
			 * Dictionary::translate('table_location');
			 * Dictionary::translate('table_client');
			 * Dictionary::translate('table_user');
			 * Dictionary::translate('table_vm');
			 */

			$data = array(
				'columns' => array(),
				'tables' => array(),
				'days' => array()
			);

			foreach ($this->COLUMNS as $column) {
				$data['columns'][] = array(
					'id' => $column,
					'name' => Dictionary::translate($column, true),
					'checked' => Request::get($column, 'on', 'string') === 'on' ? 'checked' : '',
				);
			}

			foreach ($this->TABLES as $table) {
				$data['tables'][] = array(
					'name' => Dictionary::translate('table_' . $table, true),
					'value' => $table,
					'selected' => ($this->type === $table) ? 'selected' : '',
				);
			}

			foreach (array(1,2,5,7,14,30,90) as $day) {
				$data['days'][] = array(
					'days' => $day,
					'selected' => ($day === $this->days) ? 'selected' : '',
				);
			}

			$data['lower'] = $this->lower;
			$data['upper'] = $this->upper;

			Render::addTemplate('columnChooser', $data);

			// timespan you want to see. default = last 7 days
			GetData::$from = strtotime("- " . ($this->days - 1) . " days 00:00:00");
			GetData::$to = time();
			GetData::$lowerTimeBound = $this->lower;
			GetData::$upperTimeBound = $this->upper;

			$data['data'] = $this->fetchData(GETDATA_PRINTABLE);
			Render::addTemplate('table-' . $this->type, $data);
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

	private function fetchData($flags)
	{
		switch ($this->type) {
		case 'total':
			return GetData::total($flags);
		case 'location':
			return GetData::perLocation($flags);
		case 'client':
			return GetData::perClient($flags);
		case 'user':
			return GetData::perUser($flags);
		case 'vm':
			return GetData::perVM($flags);
		}
	}

}
