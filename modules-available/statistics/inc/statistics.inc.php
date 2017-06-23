<?php



class Statistics
{

	private static $machineFields = false;

	/**
	 * @param string $machineuuid
	 * @param int $returnData
	 * @return \Machine|false
	 */
	public static function getMachine($machineuuid, $returnData)
	{
		if (self::$machineFields === false) {
			$r = new ReflectionClass('Machine');
			$props = $r->getProperties(ReflectionProperty::IS_PUBLIC);
			self::$machineFields = array_flip(array_map(function($e) { return $e->getName(); }, $props));
		}
		if ($returnData === Machine::NO_DATA) {
			unset(self::$machineFields['data']);
		} elseif ($returnData === Machine::RAW_DATA) {
			self::$machineFields['data'] = true;
		} else {
			Util::traceError('Invalid $returnData option passed');
		}
		$fields = implode(',', array_keys(self::$machineFields));
		$row = Database::queryFirst("SELECT * FROM machine WHERE machineuuid = :machineuuid", compact('machineuuid'));
		if ($row === false)
			return false;
		$m = new Machine();
		foreach ($row as $key => $val) {
			$m->{$key} = $val;
		}
		return $m;
	}

}
