<?php

/**
 * Handle communication with the database
 * This is a very thin layer between you and PDO.
 */
class Database
{
	private static $dbh = false;
	private static $statements = array();

	/**
	 * Connect to the DB if not already connected.
	 */
	private static function init()
	{
		if (self::$dbh !== false) return;
		try {
			self::$dbh = new PDO(CONFIG_SQL_DSN, CONFIG_SQL_USER, CONFIG_SQL_PASS, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
		} catch (PDOException $e) {
			Util::traceError('Connecting to the local database failed: ' . $e->getMessage());
		}
	}

	/**
	 * If you just need the first row of a query you can use this.
	 * Will return an associative array, or false if no row matches the query
	 */
	public static function queryFirst($query, $args = array())
	{
		$res = self::simpleQuery($query, $args);
		if ($res === false) return false;
		return $res->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * Execute the given query and return the number of rows affected.
	 * Mostly useful for UPDATEs or INSERTs
	 */
	public static function exec($query, $args = array())
	{
		$res = self::simpleQuery($query, $args);
		if ($res === false) return false;
		return $res->rowCount();
	}

	/**
	 * Execute the given query and return the corresponding PDOStatement object
	 * Note that this will re-use PDOStatements, so if you run the same
	 * query again with different params, do not rely on the first PDOStatement
	 * still being valid. If you need to do something fancy, use Database::prepare
	 */
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

	/**
	 * Simply calls PDO::prepare and returns the PDOStatement.
	 * You must call PDOStatement::execute manually on it.
	 */
	public static function prepare($query)
	{
		self:init();
		return self::$dbh->prepare($query);
	}

}

