<?php

class EventLog
{
		
	private static function log($type, $message, $details)
	{
		Database::exec("INSERT INTO eventlog (dateline, logtypeid, description, extra)"
			. " VALUES (UNIX_TIMESTAMP(), :type, :message, :details)", array(
				'type' => $type,
				'message' => $message,
				'details' => $details
		));
	}
	
	public static function failure($message, $details = '')
	{
		self::log('failure', $message, $details);
	}
	
	public static function warning($message, $details = '')
	{
		self::log('warning', $message, $details);
		Property::setLastWarningId(Database::lastInsertId());
	}
	
	public static function info($message, $details = '')
	{
		self::log('info', $message, $details);
		Property::setLastWarningId(Database::lastInsertId());
	}
	
}
