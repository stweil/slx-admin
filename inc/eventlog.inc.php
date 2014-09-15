<?php

class EventLog
{
	
	public static function log($eventId, $message)
	{
		Database::exec("INSERT INTO eventlog (dateline, logtypeid, description)"
			. " VALUES (UNIX_TIMESTAMP(), :eventid, :message)", array(
				'eventid' => $eventId,
				'message' => $message
		));
	}
	
}
