<?php

/**
 * Handle communication with the database
 * This is a very thin layer between you and PDO.
 */
class Database
{

	/**
	 * @var \PDO Database handle
	 */
	private static $dbh = false;
	/*
	 * @var \PDOStatement[]
	 */
	private static $statements = array();
	private static $returnErrors;
	private static $lastError = false;

	/**
	 * Connect to the DB if not already connected.
	 */
	public static function init($returnErrors = false)
	{
		if (self::$dbh !== false)
			return true;
		self::$returnErrors = $returnErrors;
		try {
			if (CONFIG_SQL_FORCE_UTF8) {
				self::$dbh = new PDO(CONFIG_SQL_DSN, CONFIG_SQL_USER, CONFIG_SQL_PASS, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
			} else {
				self::$dbh = new PDO(CONFIG_SQL_DSN, CONFIG_SQL_USER, CONFIG_SQL_PASS);
			}
		} catch (PDOException $e) {
			if (self::$returnErrors)
				return false;
			Util::traceError('Connecting to the local database failed: ' . $e->getMessage());
		}
		return true;
	}

	/**
	 * If you just need the first row of a query you can use this.
	 *
	 * @return array|boolean Associative array representing row, or false if no row matches the query
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
	 * @param boolean $ignoreError Ignore query errors and just return false
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
	 * @return string|bool return last error returned by query
	 */
	public static function lastError()
	{
		return self::$lastError;
	}

	/**
	 * Execute the given query and return the corresponding PDOStatement object
	 * Note that this will re-use PDOStatements, so if you run the same
	 * query again with different params, do not rely on the first PDOStatement
	 * still being valid. If you need to do something fancy, use Database::prepare
	 *
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
				self::$lastError = implode("\n", self::$statements[$query]->errorInfo());
				if ($ignoreError || self::$returnErrors)
					return false;
				Util::traceError("Database Error: \n" . self::$lastError);
			}
			return self::$statements[$query];
		} catch (Exception $e) {
			self::$lastError = '(' . $e->getCode() . ') ' . $e->getMessage();
			if ($ignoreError || self::$returnErrors)
				return false;
			Util::traceError("Database Error: \n" . self::$lastError);
		}
		return false;
	}

	/**
	 * Simply calls PDO::prepare and returns the PDOStatement.
	 * You must call PDOStatement::execute manually on it.
	 */
	public static function prepare($query)
	{
		self::init();
		return self::$dbh->prepare($query);
	}

}
