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

	/**
	 * Insert row into table, returning the generated key.
	 * This requires the table to have an AUTO_INCREMENT column and
	 * usually requires the given $uniqueValues to span across a UNIQUE index.
	 * The code first tries to SELECT the key for the given values without
	 * inserting first. This means this function is best used for cases
	 * where you expect that the entry already exists in the table, so
	 * only one SELECT will run. For all the entries that do not exist,
	 * an INSERT or INSERT IGNORE is run, depending on whether $additionalValues
	 * is empty or not. Another reason we don't run the INSERT (IGNORE) first
	 * is that it will increase the AUTO_INCREMENT value on InnoDB, even when
	 * no INSERT took place. So if you expect a lot of collisions you might
	 * use this function to prevent your A_I value from counting up too
	 * quickly.
	 * Other than that, this is just a dumb version of running INSERT and then
	 * getting the LAST_INSERT_ID(), or doing a query for the existing ID in
	 * case of a key collision.
	 *
	 * @param string $table table to insert into
	 * @param string $aiKey name of the AUTO_INCREMENT column
	 * @param array $uniqueValues assoc array containing columnName => value mapping
	 * @param array $additionalValues assoc array containing columnName => value mapping
	 * @return int[] list of AUTO_INCREMENT values matching the list of $values
	 */
	public static function insertIgnore($table, $aiKey, $uniqueValues, $additionalValues = false)
	{
		// Sanity checks
		if (array_key_exists($aiKey, $uniqueValues)) {
			Util::traceError("$aiKey must not be in \$uniqueValues");
		}
		if (is_array($additionalValues) && array_key_exists($aiKey, $additionalValues)) {
			Util::traceError("$aiKey must not be in \$additionalValues");
		}
		// Simple SELECT first
		$selectSql = 'SELECT ' . $aiKey . ' FROM ' . $table . ' WHERE 1';
		foreach ($uniqueValues as $key => $value) {
			$selectSql .= ' AND ' . $key . ' = :' . $key;
		}
		$selectSql .= ' LIMIT 1';
		$res = self::queryFirst($selectSql, $uniqueValues);
		if ($res !== false) {
			// Exists
			if (!empty($additionalValues)) {
				// Simulate ON DUPLICATE KEY UPDATE ...
				$updateSql = 'UPDATE ' . $table . ' SET ';
				$first = true;
				foreach ($additionalValues as $key => $value) {
					if ($first) {
						$first = false;
					} else {
						$updateSql .= ', ';
					}
					$updateSql .= $key . ' = :' . $key;
				}
				$updateSql .= ' WHERE ' . $aiKey . ' = :' . $aiKey;
				$additionalValues[$aiKey] = $res[$aiKey];
				Database::exec($updateSql, $additionalValues);
			}
			return $res[$aiKey];
		}
		// Does not exist:
		if (empty($additionalValues)) {
			$combined =& $uniqueValues;
		} else {
			$combined = $uniqueValues + $additionalValues;
		}
		// Aight, try INSERT or INSERT IGNORE
		$insertSql = 'INTO ' . $table . ' (' . implode(', ', array_keys($combined))
			. ') VALUES (:' . implode(', :', array_keys($combined)) . ')';
		if (empty($additionalValues)) {
			// Simple INSERT IGNORE
			$insertSql = 'INSERT IGNORE ' . $insertSql;
		} else {
			// INSERT ... ON DUPLICATE (in case we have a race)
			$insertSql = 'INSERT ' . $insertSql . ' ON DUPLICATE KEY UPDATE ';
			$first = true;
			foreach ($additionalValues as $key => $value) {
				if ($first) {
					$first = false;
				} else {
					$insertSql .= ', ';
				}
				$insertSql .= $key . ' =  VALUES(' . $key . ')';
			}
		}
		self::exec($insertSql, $combined);
		// Insert done, retrieve key again
		$res = self::queryFirst($selectSql, $uniqueValues);
		if ($res === false) {
			Util::traceError('Could not find value in table ' . $table . ' that was just inserted');
		}
		return $res[$aiKey];
	}

}
