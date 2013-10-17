<?php

require_once('inc/session.inc.php');

class User
{
	private static $user = false;

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
		if (Session::loadSession()) {
			self::$user['name'] = 'Hans';
			return true;
		}
		return false;
	}

	public static function login($user, $pass)
	{
		if ($user == 'test' && $pass == 'test') {
			Session::createSession();;
			Session::set('uid', 1);
			Session::set('token', md5(rand() . time() . rand() . $_SERVER['REMOTE_ADDR'] . rand() . $_SERVER['REMOTE_PORT'] . rand() . $_SERVER['HTTP_USER_AGENT']));
			Session::save();
			return true;
		}
		return false;
	}

	public static function logout()
	{
		Session::delete();
		Header('Location: ?do=main&fromlogout');
		exit(0);
	}

}

