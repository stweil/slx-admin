<?php

require_once('inc/session.inc.php');

class User
{

	private static $user = false;

	public static function isLoggedIn()
	{
		return self::$user !== false;
	}

	public static function getId()
	{
		if (!self::isLoggedIn())
			return false;
		return self::$user['userid'];
	}

	public static function getName()
	{
		if (!self::isLoggedIn())
			return false;
		return self::$user['fullname'];
	}

	public static function hasPermission($permission)
	{
		if (!self::isLoggedIn())
			return false;
		return (self::$user['permissions'] & (Permission::get($permission) | Permission::get('superadmin'))) != 0;
	}

	public static function load()
	{
		if (self::isLoggedIn())
			return true;
		if (Session::load()) {
			$uid = Session::get('uid');
			if ($uid === false || $uid < 1)
				self::logout();
			self::$user = Database::queryFirst('SELECT * FROM user WHERE userid = :uid LIMIT 1', array(':uid' => $uid));
			if (self::$user === false)
				self::logout();
			return true;
		}
		return false;
	}

	public static function login($user, $pass)
	{
		$ret = Database::queryFirst('SELECT userid, passwd FROM user WHERE login = :user LIMIT 1', array(':user' => $user));
		if ($ret === false)
			return false;
		if (!Crypto::verify($pass, $ret['passwd']))
			return false;
		Session::create();
		Session::set('uid', $ret['userid']);
		Session::set('token', md5(rand() . time() . rand() . $_SERVER['REMOTE_ADDR'] . rand() . $_SERVER['REMOTE_PORT'] . rand() . $_SERVER['HTTP_USER_AGENT']));
		Session::save();
		return true;
	}

	public static function logout()
	{
		error_log("in logout");
		Session::delete();
		Header('Location: ?do=Main&fromlogout');
		exit(0);
	}

	public static function setLastSeenEvent($eventid)
	{
		if (!self::isLoggedIn())
			return;
		Database::exec("UPDATE user SET lasteventid = :eventid WHERE userid = :userid LIMIT 1", array(
			'eventid' => $eventid,
			'userid' => self::$user['userid']
		));
		self::$user['lasteventid'] = $eventid;
	}

	public static function getLastSeenEvent()
	{
		if (!self::isLoggedIn())
			return false;
		return self::$user['lasteventid'];
	}

	public static function addUser($data){
		Database::exec ( "INSERT INTO user SET login = :login, passwd = :pass, fullname = :name, phone = :phone, email = :email, permissions = 4", $data );
		$ret = Database::queryFirst('SELECT userid FROM user WHERE login = :user LIMIT 1', array('user' => $data['login']));
		$user = array(
			'user' => $ret['userid']
		);
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '44', size = '5G', mount_point = '/tmp', user = :user", $user );
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '43', size = '20G', mount_point = '/boot', options = 'bootable', user = :user", $user );
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '40', size = '20G', mount_point = '/cache/export/dnbd3', user = :user", $user );
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '41', size = '5G', mount_point = '/home', user = :user", $user );
		Database::exec ( "INSERT INTO setting_partition SET partition_id = '82', size = '1G', user = :user", $user );
		Message::addSuccess('add-user');
		EventLog::info ( User::getName () . ' created user ' . $data['login'] );
	}

}
