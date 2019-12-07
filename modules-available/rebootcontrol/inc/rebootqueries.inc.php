<?php

class RebootQueries
{

	/**
	 * Get machines by list of UUIDs
	 * @param string[] $list list of system UUIDs
	 * @return array list of machines with machineuuid, hostname, clientip, state and locationid
	 */
	public static function getMachinesByUuid($list, $assoc = false, $columns = ['machineuuid', 'hostname', 'clientip', 'state', 'locationid'])
	{
		if (empty($list))
			return array();
		if (is_array($columns)) {
			$columns = implode(',', $columns);
		}
		$res = Database::simpleQuery("SELECT $columns FROM machine
				WHERE machineuuid IN (:list)", compact('list'));
		if (!$assoc)
			return $res->fetchAll(PDO::FETCH_ASSOC);
		$ret = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$ret[$row['machineuuid']] = $row;
		}
		return $ret;
	}

}