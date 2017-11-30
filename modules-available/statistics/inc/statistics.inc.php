<?php



class Statistics
{

	private static $machineFields = false;

	private static function initFields($returnData)
	{
		if (self::$machineFields === false) {
			$r = new ReflectionClass('Machine');
			$props = $r->getProperties(ReflectionProperty::IS_PUBLIC);
			self::$machineFields = array_flip(array_map(function(/* @var ReflectionProperty $e */ $e) { return $e->getName(); }, $props));
		}
		if ($returnData === Machine::NO_DATA) {
			unset(self::$machineFields['data']);
		} elseif ($returnData === Machine::RAW_DATA) {
			self::$machineFields['data'] = true;
		} else {
			Util::traceError('Invalid $returnData option passed');
		}
		return implode(',', array_keys(self::$machineFields));
	}

	/**
	 * @param string $machineuuid
	 * @param int $returnData What kind of data to return Machine::NO_DATA, Machine::RAW_DATA, ...
	 * @return \Machine|false
	 */
	public static function getMachine($machineuuid, $returnData)
	{
		$fields = self::initFields($returnData);

		$row = Database::queryFirst("SELECT $fields FROM machine WHERE machineuuid = :machineuuid", compact('machineuuid'));
		if ($row === false)
			return false;
		$m = new Machine();
		foreach ($row as $key => $val) {
			$m->{$key} = $val;
		}
		return $m;
	}

	/**
	 * @param string $ip
	 * @param int $returnData What kind of data to return Machine::NO_DATA, Machine::RAW_DATA, ...
	 * @param string $sort something like 'lastseen ASC' - not sanitized, don't pass user input!
	 * @return \Machine[] list of matches
	 */
	public static function getMachinesByIp($ip, $returnData, $sort = false)
	{
		$fields = self::initFields($returnData);

		if ($sort === false) {
			$sort = '';
		} else {
			$sort = "ORDER BY $sort";
		}
		$res = Database::simpleQuery("SELECT $fields FROM machine WHERE clientip = :ip $sort", compact('ip'));
		$list = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$m = new Machine();
			foreach ($row as $key => $val) {
				$m->{$key} = $val;
			}
			$list[] = $m;
		}
		return $list;
	}

}
