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
	private $COLUMNS = array('lastLogout', 'lastStart', 'location', 'longSessions', 'medianSessionLength',
		'sessions', 'shortSessions', 'totalOffTime', 'totalTime');

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

		// timespan you want to see. default = last 7 days
		GetData::$from = strtotime("- " . ($this->days - 1) . " days 00:00:00");
		GetData::$to = time();
		GetData::$lowerTimeBound = $this->lower;
		GetData::$upperTimeBound = $this->upper;

		// Export - handle in doPreprocess so we don't render the menu etc.
		if ($this->action === 'export') {
			$this->doExport();
			// Does not return
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
					'id' => 'col_' . $column,
					'name' => Dictionary::translateFile('template-tags', 'lang_' . $column, true),
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

	private function doExport()
	{
		$format = Request::get('format', 'json', 'string');
		$printable = (bool)Request::get('printable', 0, 'int');
		$flags = 0;
		if ($printable) {
			$flags |= GETDATA_PRINTABLE;
		}
		$res = $this->fetchData($flags);
		// TODO: Filter unwanted columns
		Header('Content-Disposition: attachment; filename=' . 'statistics-' . date('Y.m.d-H.i.s') . '.' . $format);
		switch ($format) {
		case 'json':
			Header('Content-Type: application/json; charset=utf-8');
			$output = json_encode(array('data' => $res));
			break;
		case 'csv':
			if (!is_array($res)) {
				die('Error fetching data.');
			}
			Header('Content-Type: text/csv; charset=utf-8');
			$fh = fopen('php://output', 'w');
			// Output UTF-8 BOM - Excel needs this to automatically decode as UTF-8
			// (and since Excel is the only sane reason to export as csv, just always do it)
			fputs($fh, chr(239) . chr(187) . chr(191));
			// Output
			if (isset($res[0]) && is_array($res[0])) {
				// List of rows
				fputcsv($fh, array_keys($res[0]), ';');
				foreach ($res as $row) {
					fputcsv($fh, $row, ';');
				}
			} else {
				// Single assoc array
				fputcsv($fh, array_keys($res), ';');
				fputcsv($fh, $res, ';');
			}
			fclose($fh);
			exit();
			break;
		case 'xml':
			$xml_data = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8" ?><data></data>');
			$this->array_to_xml($res, $xml_data, 'row');
			$output = $xml_data->asXML();
			Header('Content-Type: text/xml; charset=utf-8');
			break;
		default:
			Header('Content-Type: text/plain');
			$output = 'Invalid format: ' . $format;
		}
		die($output);
	}

	/**
	 * @param $data array Data to encode
	 * @param $xml_data \SimpleXMLElement XML Object to append to
	 */
	private function array_to_xml($data, $xml_data, $parentName = 'row')
	{
		foreach ($data as $key => $value) {
			if (is_numeric($key)) {
				$key = $parentName;
			}
			if (is_array($value)) {
				$subnode = $xml_data->addChild($key);
				$this->array_to_xml($value, $subnode, $key);
			} else {
				$xml_data->addChild($key, htmlspecialchars($value));
			}
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
