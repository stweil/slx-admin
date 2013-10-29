<?php

$verboseDebug = true;

class Util
{
	public static function traceError($message)
	{
		global $verboseDebug;
		Header('Content-Type: text/plain; charset=utf-8');
		echo "--------------------\nFlagrant system error:\n$message\n--------------------\n\n";
		if (isset($verboseDebug) && $verboseDebug) {
			debug_print_backtrace();
			echo "\n\n";
			$vars = get_defined_vars();
			print_r($vars);
		}
		exit(0);
	}

	public static function redirect($location)
	{
		Session::save();
		Header('Location: ' . $location);
		exit(0);
	}

	public static function verifyToken()
	{
		if (Session::get('token') === false) return true;
		if (isset($_REQUEST['token']) && Session::get('token') === $_REQUEST['token']) return true;
		Message::addError('token');
		return false;
	}

	function markup($string)
	{
		$string = htmlspecialchars($string);
		$string = preg_replace('#(^|[\n \-_/\.])\*(.+?)\*($|[ \-_/\.\!\?,])#is', '$1<b>$2</b>$3', $string);
		$string = preg_replace('#(^|[\n \-\*/\.])_(.+?)_($|[ \-\*/\.\!\?,])#is', '$1<u>$2</u>$3', $string);
		$string = preg_replace('#(^|[\n \-_\*\.])/(.+?)/($|[ \-_\*\.\!\?,])#is', '$1<i>$2</i>$3', $string);
		return nl2br($string);
	}

}

