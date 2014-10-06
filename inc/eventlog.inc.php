<?php

class EventLog
{
		
	private static function log($type, $message)
	{
		Database::exec("INSERT INTO eventlog (dateline, logtypeid, description)"
			. " VALUES (UNIX_TIMESTAMP(), :type, :message)", array(
				'type' => $type,
				'message' => $message
		));
	}
	
	public static function failure($message)
	{
		self::log('failure', $message);
	}
	
	public static function warning($message)
	{
		self::log('warning', $message);
	}
	
	public static function info($message)
	{
		self::log('info', $message);
	}
	
}
