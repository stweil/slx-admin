<?php

class Database
{
	private static $dbh = false;
	private static $statements = array();

	public static function init()
	{
		if (self::$dbh !== false) return;
		try {
			self::$dbh = new PDO(CONFIG_SQL_DSN, CONFIG_SQL_USER, CONFIG_SQL_PASS, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
		} catch (PDOException $e) {
			Util::traceError('Connecting to the local database failed: ' . $e->getMessage());
		}
	}

	public static function queryFirst($query, $args = array())
	{
		$res = self::simpleQuery($query, $args);
		if ($res === false) return false;
		return $res->fetch(PDO::FETCH_ASSOC);
	}

	public static function exec($query, $args = array())
	{
		$res = self::simpleQuery($query, $args);
		if ($res === false) return false;
		return $res->rowCount();
	}

	public static function simpleQuery($query, $args = array())
	{
		self::init();
		//if (empty($args)) Util::traceError('Query with zero arguments!');
		if (!isset(self::$statements[$query])) {
			self::$statements[$query] = self::$dbh->prepare($query);
		} else {
			self::$statements[$query]->closeCursor();
		}
		if (self::$statements[$query]->execute($args) === false) {
			Util::traceError("Database Error: \n" . implode("\n", self::$statements[$query]->errorInfo()));
		}
		return self::$statements[$query];
	}

	public static function prepare($query)
	{
		self:init();
		return self::$dbh->prepare($query);
	}

}

