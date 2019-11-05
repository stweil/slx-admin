<?php

class StatisticsHooks
{

	private static $row = false;

	private static function getRow($machineuuid)
	{
		if (self::$row !== false)
			return;
		self::$row = Database::queryFirst('SELECT hostname, clientip, locationid FROM machine WHERE machineuuid = :machineuuid',
			['machineuuid' => $machineuuid]);
	}

	public static function getBaseconfigName($machineuuid)
	{
		self::getRow($machineuuid);
		if (self::$row === false)
			return false;
		return self::$row['hostname'] ? self::$row['hostname'] : self::$row['clientip'];
	}

	public static function baseconfigLocationResolver($machineuuid)
	{
		self::getRow($machineuuid);
		if (self::$row === false)
			return 0;
		return (int)self::$row['locationid'];
	}

	/**
	 * Hook to get inheritance tree for all config vars
	 * @param int $machineuuid MachineUUID currently being edited
	 */
	public static function baseconfigInheritance($machineuuid)
	{
		self::getRow($machineuuid);
		if (self::$row === false)
			return [];
		BaseConfig::prepareWithOverrides([
			'locationid' => self::$row['locationid']
		]);
		return ConfigHolder::getRecursiveConfig(true);
	}

}