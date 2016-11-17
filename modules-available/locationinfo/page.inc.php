<?php

class Page_LocationInfo extends Page
{

	private $action;

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
		$getAction = Request::get('action');
		if (empty($getAction)) {
			Util::redirect('?do=locationinfo&action=infoscreen');
		}

		if ($getAction === 'infoscreen') {
			$this->getInfoScreenTable();
		}

		if($getAction == 'updateroomdb') {
			$this->updateInfoscreenDb();
			Util::redirect('?do=locationinfo&action=infoscreen');
		}

		if ($getAction === 'hide') {
			$roomId = Request::get('id');
			$hiddenValue = Request::get('value');
			$this->toggleHidden($roomId, $hiddenValue);
			Util::redirect('?do=locationinfo&action=infoscreen');
		}
	}

	protected function toggleHidden($id, $val) {
		Database::exec("UPDATE `locationinfo` SET hidden = $val WHERE locationid = $id");
	}

	protected function getInfoScreenTable() {

		$dbquery = Database::simpleQuery("SELECT * FROM `locationinfo`");

		$pcs = array();
		while($roominfo=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$data = array();
			$data['locationid'] = $roominfo['locationid'];
			$data['hidden'] = $roominfo['hidden'];

			$inUseCounter = 0;
			$totalPcCounter = 0;
			$data['computers'] = json_decode($roominfo['computers'], true);

			foreach ($data['computers'] as $value) {
				if ($value['inUse'] == 1) {
					$inUseCounter++;
				}
				$totalPcCounter++;
			}
			$data['inUse'] = $inUseCounter;
			$data['totalPcs'] = $totalPcCounter;
			$pcs[] = $data;
		}

		Render::addTemplate('location-info', array(
			'list' => array_values($pcs),
		));
	}

	protected function updateInfoscreenDb() {
		$dbquery = Database::simpleQuery("SELECT DISTINCT locationid FROM `machine` WHERE locationid IS NOT NULL");
		while($roominfo=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$this->updatePcInfos($roominfo['locationid']);
		}
	}

	/**
 	 * AJAX
 	 */
	protected function doAjax()
	{
		User::load();
		if (!User::isLoggedIn()) {
			die('Unauthorized');
		}
		$action = Request::any('action');
		if ($action === 'pcsubtable') {
			$id = Request::any('id');
			$this->ajaxShowLocation($id);

		}
	}

	private function ajaxShowLocation($id)
	{
		$dbquery = Database::simpleQuery("SELECT * FROM `locationinfo` WHERE locationid = $id");

		$data = array();
		while($roominfo=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$data = json_decode($roominfo['computers'], true);
		}

		echo Render::parse('pcsubtable', array(
			'list' => array_values($data),
		));
	}

}
