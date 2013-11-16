<?php

require_once('config.php');

@mkdir(CONFIG_SESSION_DIR, 0700, true);
@chmod(CONFIG_SESSION_DIR, 0700);
if (!is_writable(CONFIG_SESSION_DIR)) die('Config error: Session Path not writable!');

class Session
{
	private static $sid = false;
	private static $data = false;
	
	private static function generateSessionId()
	{
		if (self::$sid !== false) Util::traceError('Error: Asked to generate session id when already set.');
		self::$sid = sha1(
			mt_rand(0, 65535)
			. $_SERVER['REMOTE_ADDR']
			. mt_rand(0, 65535)
			. $_SERVER['REMOTE_PORT']
			. mt_rand(0, 65535)
			. $_SERVER['HTTP_USER_AGENT']
			. mt_rand(0, 65535)
			. microtime(true)
			. mt_rand(0, 65535)
		);
	}

	public static function create()
	{
		self::generateSessionId();
		self::$data = array();
	}

	public static function load()
	{
		// Try to load session id from cookie
		if (!self::loadSessionId()) return false;
		// Succeded, now try to load session data. If successful, job is done
		if (self::readSessionData()) return true;
		// Loading session data failed
		self::delete();
	}

	public static function get($key)
	{
		if (!isset(self::$data[$key])) return false;
		return self::$data[$key];
	}

	public static function set($key, $value)
	{
		if (self::$data === false) Util::traceError('Tried to set session data with no active session');
		self::$data[$key] = $value;
	}
	
	private static function loadSessionId()
	{
		if (self::$sid !== false) die('Error: Asked to load session id when already set.');
		if (empty($_COOKIE['sid'])) return false;
		$id = preg_replace('/[^a-zA-Z0-9]/', '', $_COOKIE['sid']);
		if (empty($id)) return false;
		self::$sid = $id;
		return true;
	}
	
	public static function delete()
	{
		if (self::$sid === false) return;
		@unlink(self::getSessionFile());
		@setcookie('sid', '', time() - 8640000, null, null, !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off', true);
		self::$sid = false;
		self::$data = false;
	}
	
	private static function getSessionFile()
	{
		if (self::$sid === false) Util::traceError('Error: Tried to access session file when no session id was set.');
		return CONFIG_SESSION_DIR . '/' . self::$sid;
	}

	private static function readSessionData()
	{
		if (self::$data !== false) Util::traceError('Tried to call read session data twice');
		$sessionfile = self::getSessionFile();
		if (!is_readable($sessionfile) || filemtime($sessionfile) + CONFIG_SESSION_TIMEOUT < time()) {
			@unlink($sessionfile);
			return false;
		}	
		self::$data = @unserialize(@file_get_contents($sessionfile));
		if (self::$data === false) return false;
		return true;
	}
	
	public static function save()
	{
		if (self::$sid === false || self::$data === false) return; //Util::traceError('Called saveSession with no active session');
		$sessionfile = self::getSessionFile();
		$ret = @file_put_contents($sessionfile, @serialize(self::$data));
		if (!$ret) Util::traceError('Storing session data  in ' . $sessionfile . ' failed.');
		$ret = @setcookie('sid', self::$sid, time() + CONFIG_SESSION_TIMEOUT, null, null, !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off', true);
		if (!$ret) Util::traceError('Error: Could not set Cookie for Client (headers already sent)');
	}
}

