<?php

/**
 * Class to add entries to the event log. Technically this class belongs to the
 * eventlog module, but since it is used in so many places, this helper resides
 * in the general inc directory instead of the eventlog's inc directory, since
 * that would require to add "if (Module::isAvailable('eventlog')) { ... }"
 * all over the place. Instead, the availability check was moved here.
 */
class EventLog
{
		
	private static function log($type, $message, $details)
	{
		if (!Module::isAvailable('eventlog')) {
			// Eventlog module does not exist; the eventlog table might not exist, so bail out
			error_log($message);
			return;
		}
		Database::exec("INSERT INTO eventlog (dateline, logtypeid, description, extra)"
			. " VALUES (UNIX_TIMESTAMP(), :type, :message, :details)", array(
				'type' => $type,
				'message' => $message,
				'details' => $details
		), true);
	}
	
	public static function failure($message, $details = '')
	{
		self::log('failure', $message, $details);
		Property::setLastWarningId(Database::lastInsertId());
	}
	
	public static function warning($message, $details = '')
	{
		self::log('warning', $message, $details);
		Property::setLastWarningId(Database::lastInsertId());
	}
	
	public static function info($message, $details = '')
	{
		self::log('info', $message, $details);
	}
	
	/**
	 * DELETE ENTIRE EVENT LOG!
	 */
	public static function clear()
	{
		if (!Module::isAvailable('eventlog'))
			return;
		Database::exec("TRUNCATE eventlog");
	}
	
}
