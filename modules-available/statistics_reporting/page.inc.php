<?php


class Page_Statistics_Reporting extends Page
{

	private $action;
	private $type;

	// "Constants"
	/**
	 * @var int
	 */
	private $days;
	/**
	 * @var int
	 */
	private $lower;
	/**
	 * @var int
	 */
	private $upper;

	/**
	 * @var array Names of columns that are being used by the various tables
	 */
	private $COLUMNS = array('locationname', 'totalTime', 'medianSessionLength', 'sessions', 'longSessions', 'shortSessions',
		'totalOffTime', 'totalStandbyTime', 'totalSessionTime', 'totalIdleTime', 'lastLogout', 'lastStart');

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
		GetData::$from = strtotime("-" . ($this->days - 1) . " days 00:00:00");
		GetData::$to = time();
		GetData::$lowerTimeBound = $this->lower;
		GetData::$upperTimeBound = $this->upper;
		/*
		GetData::$from = strtotime("2017-12-01 00:00:00");
		GetData::$to = strtotime("2017-12-15 00:00:00");
		GetData::$lowerTimeBound = 1;
		GetData::$upperTimeBound = 2;
		*/

		// Export - handle in doPreprocess so we don't render the menu etc.
		if ($this->action === 'export') {
			if (User::hasPermission("table.export") && User::hasPermission("table.view.$this->type")) {
				$this->doExport();
				// Does not return
			}
		}
		// Get report - fetch data exactly the way it would automatically be reported
		// so the user can know what is going on
		if ($this->action === 'getreport') {
			if(User::hasPermission("reporting.download")) {
				$report = RemoteReport::generateReport(time());
				Header('Content-Disposition: attachment; filename=remote-report.json');
				Header('Content-Type: application/json; charset=utf-8');
				die(json_encode($report));
			}
		}
		User::assertPermission('*');
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

			$forceOn = (Request::get('type') === false);
			foreach ($this->COLUMNS as $column) {
				$data['columns'][] = array(
					'id' => 'col_' . $column,
					'name' => Dictionary::translateFile('template-tags', 'lang_' . $column, true),
					'checked' => ($forceOn || Request::get('col_' . $column, 'off', 'string') !== 'off') ? 'checked' : '',
				);
			}

			foreach ($this->TABLES as $table) {
				$data['tables'][] = array(
					'name' => Dictionary::translate('table_' . $table, true),
					'value' => $table,
					'allowed' => User::hasPermission("table.view.$table"),
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

			if (RemoteReport::isReportingEnabled()) {
				$data['settingsButtonClass'] = 'default';
				$data['reportChecked'] = 'checked';
			} else {
				$data['settingsButtonClass'] = 'danger';
			}

			$data['allowedExport'] = User::hasPermission("table.export") && User::hasPermission("table.view.$this->type");
			$data['allowedDownload'] = User::hasPermission("reporting.download");
			$data['allowedReportChange'] = User::hasPermission("reporting.change");

			Render::addTemplate('columnChooser', $data);

			$data['data'] = $this->fetchData(GETDATA_PRINTABLE);

			if (User::hasPermission("table.view.$this->type")) {
				Render::addTemplate('table-' . $this->type, $data);
			}
		}
	}

	protected function doAjax()
	{
		$this->action = Request::any('action', false, 'string');
		if ($this->action === 'setReporting') {
			if (User::hasPermission("reporting.change")) {
				$state = Request::post('reporting', false, 'string');
				if ($state === false) {
					die('Missing setting value.');
				}
				RemoteReport::setReportingEnabled($state);
				$data = array();
				if (RemoteReport::isReportingEnabled()) {
					$data['class'] = 'default';
					$data['checked'] = true;
				} else {
					$data['class'] = 'danger';
				}
				Header('Content-Type: application/json; charset=utf-8');
				die(json_encode($data));
			} else {
				die('No permission.');
			}
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
		// Filter unwanted columns
		if (isset($res[0])) {
			foreach ($this->COLUMNS as $column) {
				if (Request::get('col_' . $column, 'delete', 'string') === 'delete') {
					foreach ($res as &$row) {
						unset($row[$column], $row[$column . '_s']);
						if ($column === 'locationname') {
							unset($row['locationid']);
						}
					}
				} elseif ($printable && isset($row[0][$column . '_s'])) {
					foreach ($res as &$row) {
						unset($row[$column]);
					}
				} elseif ($column === 'locationname' && (isset($res[0]['locationname']) || isset($res[0]['locationid']))) {
					foreach ($res as &$row) {
						if ($printable) {
							unset($row['locationid']);
						} else {
							unset($row['locationname']);
						}
					}
				}
			}
			unset($row);
		}
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
		// TODO: Make all modes location-aware, filter while querying, not after
		switch ($this->type) {
		case 'total':
			return GetData::total($flags);
		case 'location':
			$data = GetData::perLocation($flags);
			$highlight = Request::get('location', false, 'int');
			if ($highlight !== false) {
				foreach ($data as &$row) {
					if ($row['locationid'] == $highlight) {
						$row['highlight'] = true;
					}
				}
			}
			// only show locations which you have permission for
			$filterLocs = User::getAllowedLocations("table.view.location");
			foreach ($data as $key => $row) {
				if (!in_array($row['locationid'], $filterLocs)) {
					unset($data[$key]);
				}
			}
			// correct indexing of array after deletions
			$data = array_values($data);
			return $data;
		case 'client':
			$data = GetData::perClient($flags, Request::any('new', false, 'string'));
			// only show clients from locations which you have permission for
			$filterLocs = User::getAllowedLocations("table.view.client");
			foreach ($data as $key => $row) {
				if (!in_array($row['locationid'], $filterLocs)) {
					unset($data[$key]);
				}
			}
			// correct indexing of array after deletions
			$data = array_values($data);
			return $data;
		case 'user':
			return GetData::perUser($flags);
		case 'vm':
			return GetData::perVM($flags);
		}
		return false;
	}

}
