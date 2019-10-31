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

	public static function getBaseconfigParent($machineuuid)
	{
		return false; // TODO
	}

	public static function baseconfigLocationResolver($machineuuid)
	{
		self::getRow($machineuuid);
		if (self::$row === false)
			return 0;
		return (int)self::$row['locationid'];
	}

}