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
	 * Get database schema version - used for checking for updates
	 * @return int Version of db schema
	 */
	public static function getExpectedSchemaVersion()
	{
		return 7;
	}
	
	public static function needSchemaUpdate()
	{
		return Property::getCurrentSchemaVersion() < self::getExpectedSchemaVersion();
	}

	/**
	 * Connect to the DB if not already connected.
	 */
	private static function init()
	{
		if (self::$dbh !== false)
			return;
		try {
			if (CONFIG_SQL_FORCE_UTF8)
				self::$dbh = new PDO(CONFIG_SQL_DSN, CONFIG_SQL_USER, CONFIG_SQL_PASS, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
			else
				self::$dbh = new PDO(CONFIG_SQL_DSN, CONFIG_SQL_USER, CONFIG_SQL_PASS);
		} catch (PDOException $e) {
			Util::traceError('Connecting to the local database failed: ' . $e->getMessage());
		}
	}

	/**
	 * If you just need the first row of a query you can use this.
	 * Will return an associative array, or false if no row matches the query
	 */
	public static function queryFirst($query, $args = array(), $ignoreError = false)
	{
		$res = self::simpleQuery($query, $args, $ignoreError);
		if ($res === false)
			return false;
		return $res->fetch(PDO::FETCH_ASSOC);
	}


	/**
	 * Execute the given query and return the number of rows affected.
	 * Mostly useful for UPDATEs or INSERTs
	 * 
	 * @param string $query Query to run
	 * @param array $args Arguments to query
	 * @return int|boolean Number of rows affected, or false on error
	 */
	public static function exec($query, $args = array(), $ignoreError = false)
	{
		$res = self::simpleQuery($query, $args, $ignoreError);
		if ($res === false)
			return false;
		return $res->rowCount();
	}

	/**
	 * Get id (promary key) of last row inserted.
	 * 
	 * @return int the id
	 */
	public static function lastInsertId()
	{
		return self::$dbh->lastInsertId();
	}

	/**
	 * Execute the given query and return the corresponding PDOStatement object
	 * Note that this will re-use PDOStatements, so if you run the same
	 * query again with different params, do not rely on the first PDOStatement
	 * still being valid. If you need to do something fancy, use Database::prepare
	 * @return \PDOStatement The query result object
	 */
	public static function simpleQuery($query, $args = array(), $ignoreError = false)
	{
		self::init();
		try {
			if (!isset(self::$statements[$query])) {
				self::$statements[$query] = self::$dbh->prepare($query);
			} else {
				self::$statements[$query]->closeCursor();
			}
			if (self::$statements[$query]->execute($args) === false) {
				if ($ignoreError)
					return false;
				Util::traceError("Database Error: \n" . implode("\n", self::$statements[$query]->errorInfo()));
			}
			return self::$statements[$query];
		} catch (Exception $e) {
				if ($ignoreError)
					return false;
				Util::traceError("Database Error: \n" . $e->getMessage());
		}
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
