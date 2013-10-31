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
		return self::$user['fullname'];
	}

	public static function hasPermission($permission)
	{
		if (self::$user === false) return false;
		return (self::$user['permissions'] & Permission::get($permission)) != 0;
	}

	public static function load()
	{
		if (Session::load()) {
			$uid = Session::get('uid');
			if ($uid === false || $uid < 1) self::logout();
			self::$user = Database::queryFirst('SELECT * FROM user WHERE userid = :uid LIMIT 1', array(':uid' => $uid));
			if (self::$user === false) self::logout();
			return true;
		}
		return false;
	}

	public static function login($user, $pass)
	{
		$ret = Database::queryFirst('SELECT userid, passwd FROM user WHERE login = :user LIMIT 1', array(':user' => $user));
		if ($ret === false) return false;
		if (!Crypto::verify($pass, $ret['passwd'])) return false;
		Session::create();
		Session::set('uid', $ret['userid']);
		Session::set('token', md5(rand() . time() . rand() . $_SERVER['REMOTE_ADDR'] . rand() . $_SERVER['REMOTE_PORT'] . rand() . $_SERVER['HTTP_USER_AGENT']));
		Session::save();
		return true;
	}

	public static function logout()
	{
		Session::delete();
		Header('Location: ?do=main&fromlogout');
		exit(0);
	}

}

