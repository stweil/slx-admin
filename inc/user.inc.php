<?php

require_once('inc/session.inc.php');

class User
{
	private static $user = false;
	private static $session = false;

	public static function isLoggedIn()
	{
		return self::$user !== false;
	}

	public static function getName()
	{
		if (self::$user === false) return false;
		return self::$user['name'];
	}

	public static function load()
	{
		if (isset($_REQUEST['PHPSESSID']) || isset($_COOKIE['PHPSESSID'])) {
			session_start();
			if (!isset($_SESSION['uid']) || !is_numeric($_SESSION['uid'])) {
				self::logout();
				return false;
			}
			// TODO: Query user db for persistent data
			$user['name'] = 'Hans';
			return true;
		}
		return false;
	}

	public static function login($user, $pass)
	{
		if ($user == 'test' && $pass == 'test') {
			session_start();
			$_SESSION['uid'] = 1;
			$_SESSION['token'] = md5(rand() . time() . rand() . $_SERVER['REMOTE_ADDR'] . rand() . $_SERVER['REMOTE_PORT'] . rand() . $_SERVER['HTTP_USER_AGENT']);
			session_write_close();
			return true;
		}
		return false;
	}

	public static function logout()
	{
		session_unset();
		session_destroy();
		if (setcookie('PHPSESSID', '', time() - 86400)) {
			Header('Location: ?do=main&fromlogout');
		}
		exit(0);
	}

}

