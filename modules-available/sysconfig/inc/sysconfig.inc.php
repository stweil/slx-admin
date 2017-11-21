<?php

class SysConfig
{

	const GLOBAL_MINIMAL_CONFIG = '/opt/openslx/configs/config-global.tgz';

	public static function getAll()
	{
		$res = Database::simpleQuery("SELECT c.configid, c.title, c.filepath, c.status, Group_Concat(cl.locationid) AS locs FROM configtgz c"
			. " LEFT JOIN configtgz_location cl USING (configid) GROUP BY c.configid");
		$ret = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$ret[] = $row;
		}
		return $ret;
	}

}