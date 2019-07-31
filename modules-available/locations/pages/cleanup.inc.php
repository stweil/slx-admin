<?php

class SubPage
{

	public static function doPreprocess($action)
	{
		if ($action === 'resetmachines') {
			self::resetMachines();
			return true;
		}
		if ($action === 'movemachines') {
			self::moveMachines();
			return true;
		}
		return false;
	}

	public static function doRender($action)
	{
		$list = self::loadForLocation();
		if ($list === false)
			return true;
		Permission::addGlobalTags($list['perms'], NULL, ['subnets.edit', 'location.view']);
		Render::addTemplate('mismatch-cleanup', $list);
		return true;
	}

	public static function doAjax($action)
	{
		return false;
	}

	private static function resetMachines()
	{
		$delete = self::getSelectedMachines(true);
		if ($delete === false)
			return;
		$num = Database::exec("UPDATE machine SET fixedlocationid = NULL, position = '' WHERE machineuuid IN (:machines)",
			['machines' => $delete]);
		Message::addSuccess('reset-n-machines', $num);
	}

	private static function moveMachines()
	{
		$move = self::getSelectedMachines(false);
		if ($move === false)
			return;
		// Move to subnet's location, or NULL if position field was empty (Which should never be the case)
		$num = Database::exec("UPDATE machine SET fixedlocationid = If(Length(position) > 0, subnetlocationid, NULL) WHERE machineuuid IN (:machines)",
			['machines' => $move]);
		Message::addSuccess('moved-n-machines', $num);
	}

	private static function getSelectedMachines($forDelete)
	{
		$list = self::loadForLocation();
		if ($list === false)
			return false;
		$machines = Request::post('machines', false, 'array');
		if ($machines === false) {
			Message::addError('main.parameter-missing', 'machines');
			return false;
		}
		$valid = array_map(function($item) use ($forDelete) {
			return $item['canmove'] || $forDelete ? $item['machineuuid'] : 'x';
		}, $list['clients']);
		$retList = array_filter($machines, function($item) use ($valid) {
			return in_array($item, $valid);
		});
		if (empty($retList)) {
			Message::addError('no-valid-machines-selected');
			return false;
		}
		return $retList;
	}

	private static function loadForLocation()
	{
		$locationid = Request::any('locationid', false, 'int');
		if ($locationid === false) {
			Message::addError('main.parameter-missing', 'locationid');
			return false;
		}
		$list = LocationUtil::getMachinesWithLocationMismatch($locationid, true);
		if (empty($list)) {
			Message::addInfo('no-mismatch-location');
			return false;
		}
		return $list;
	}

}