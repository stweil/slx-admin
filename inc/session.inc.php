<?php

require_once('config.php');

@mkdir(CONFIG_SESSION_DIR, 0700, true);
@chmod(CONFIG_SESSION_DIR, 0700);
if (!is_writable(CONFIG_SESSION_DIR)) die('Config error: Session Path not writable!');

class Session
{
	private static $sid = false;
	private static $data = false;
	
	private static function generateSessionId($salt)
	{
		if (self::$sid !== false) Util::traceError('Error: Asked to generate session id when already set.');
		self::$sid = sha1($salt . ','
			. mt_rand(0, 65535)
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

	public static function create($salt = '')
	{
		self::generateSessionId($salt);
		self::$data = array();
	}

	public static function load()
	{
		// Try to load session id from cookie
		if (!self::loadSessionId()) return false;
		// Succeeded, now try to load session data. If successful, job is done
		if (self::readSessionData()) return true;
		// Loading session data failed
		self::delete();
		return false;
	}

	public static function get($key)
	{
		if (!isset(self::$data[$key]) || !is_array(self::$data[$key])) return false;
		return self::$data[$key][0];
	}

	/**
	 * @param string $key key of entry
	 * @param mixed $value data to store for key, false = delete
	 * @param int|false $validMinutes validity in minutes, or false = forever
	 */
	public static function set($key, $value, $validMinutes = false)
	{
		if (self::$data === false) Util::traceError('Tried to set session data with no active session');
		if ($value === false) {
			unset(self::$data[$key]);
		} else {
			self::$data[$key] = [$value, $validMinutes === false ? false : time() + $validMinutes * 60];
		}
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
		self::deleteCookie();
		self::$sid = false;
		self::$data = false;
	}

	public static function deleteCookie()
	{
		Util::clearCookie('sid');
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
		if (self::$data === false)
			return false;
		$now = time();
		$save = false;
		foreach (array_keys(self::$data) as $key) {
			if (self::$data[$key][1] !== false && self::$data[$key][1] < $now) {
				unset(self::$data[$key]);
				$save = true;
			}
		}
		if ($save) {
			self::save();
		}
		return true;
	}
	
	public static function save()
	{
		if (self::$sid === false || self::$data === false) return; //Util::traceError('Called saveSession with no active session');
		$sessionfile = self::getSessionFile();
		$ret = @file_put_contents($sessionfile, @serialize(self::$data));
		if (!$ret) Util::traceError('Storing session data  in ' . $sessionfile . ' failed.');
		Util::clearCookie('sid');
		$ret = setcookie('sid', self::$sid, time() + CONFIG_SESSION_TIMEOUT, null, null, !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off', true);
		if (!$ret) Util::traceError('Error: Could not set Cookie for Client (headers already sent)');
	}
}

