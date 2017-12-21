<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', false, 'string');
		if ($action !== false) {
			self::handleProjector($action);
		}
	}

	private static function handleProjector($action)
	{
		$hwid = Request::post('hwid', false, 'int');
		if ($hwid === false) {
			Util::traceError('Param hwid missing');
		}
		if ($action === 'addprojector') {
			Database::exec('INSERT INTO statistic_hw_prop (hwid, prop, value)'
				. ' VALUES (:hwid, :prop, :value)', array(
				'hwid' => $hwid,
				'prop' => 'projector',
				'value' => 'true',
			));
		} else {
			Database::exec('DELETE FROM statistic_hw_prop WHERE hwid = :hwid AND prop = :prop', array(
				'hwid' => $hwid,
				'prop' => 'projector',
			));
		}
		if (Module::isAvailable('sysconfig')) {
			ConfigTgz::rebuildAllConfigs();
		}
		Util::redirect('?do=statistics&show=projectors');
	}

	public static function doRender()
	{
		self::showProjectors();
	}

	private static function showProjectors()
	{
		$res = Database::simpleQuery('SELECT h.hwname, h.hwid FROM statistic_hw h'
			. " INNER JOIN statistic_hw_prop p ON (h.hwid = p.hwid AND p.prop = :projector)"
			. " WHERE h.hwtype = :screen ORDER BY h.hwname ASC", array(
			'projector' => 'projector',
			'screen' => DeviceType::SCREEN,
		));
		$data = array(
			'projectors' => $res->fetchAll(PDO::FETCH_ASSOC)
		);
		Render::addTemplate('projector-list', $data);
	}

}