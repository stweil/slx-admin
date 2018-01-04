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
	private static $explainList = array();
	private static $queryCount = 0;
	private static $queryTime = 0;

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
		if (CONFIG_DEBUG) {
			register_shutdown_function(function() {
				self::examineLoggedQueries();
			});
		}
		return true;
	}

	/**
	 * If you just need the first row of a query you can use this.
	 *
	 * @return array|boolean Associative array representing row, or false if no row matches the query
	 */
	public static function queryFirst($query, $args = array(), $ignoreError = null)
	{
		$res = self::simpleQuery($query, $args, $ignoreError);
		if ($res === false)
			return false;
		return $res->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * If you need all rows for a query as plain array you can use this.
	 * Don't use this if you want to do further processing of the data, to save some
	 * memory.
	 *
	 * @return array|bool List of associative arrays representing rows, or false on error
	 */
	public static function queryAll($query, $args = array(), $ignoreError = null)
	{
		$res = self::simpleQuery($query, $args, $ignoreError);
		if ($res === false)
			return false;
		return $res->fetchAll(PDO::FETCH_ASSOC);
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
	public static function exec($query, $args = array(), $ignoreError = null)
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
	 * @return \PDOStatement|false The query result object
	 */
	public static function simpleQuery($query, $args = array(), $ignoreError = null)
	{
		self::init();
		if (CONFIG_DEBUG && !isset(self::$explainList[$query]) && preg_match('/^\s*SELECT/is', $query)) {
			self::$explainList[$query] = [$args];
		}
		// Support passing nested arrays for IN statements, automagically refactor
		$oquery = $query;
		self::handleArrayArgument($query, $args);
		try {
			if (!isset(self::$statements[$query])) {
				self::$statements[$query] = self::$dbh->prepare($query);
			} else {
				self::$statements[$query]->closeCursor();
			}
			$start = microtime(true);
			if (self::$statements[$query]->execute($args) === false) {
				self::$lastError = implode("\n", self::$statements[$query]->errorInfo());
				if ($ignoreError === true || ($ignoreError === null && self::$returnErrors))
					return false;
				Util::traceError("Database Error: \n" . self::$lastError);
			}
			if (CONFIG_DEBUG) {
				$duration = microtime(true) - $start;
				self::$queryTime += $duration;
				$duration = round($duration, 3);
				if (isset(self::$explainList[$oquery])) {
					self::$explainList[$oquery][] = $duration;
				} elseif ($duration > 0.1) {
					error_log('SLOW ****** ' . $duration . "s *********\n" . $query);
				}
				self::$queryCount += 1;
			}
			return self::$statements[$query];
		} catch (Exception $e) {
			self::$lastError = '(' . $e->getCode() . ') ' . $e->getMessage();
			if ($ignoreError === true || ($ignoreError === null && self::$returnErrors))
				return false;
			Util::traceError("Database Error: \n" . self::$lastError);
		}
		return false;
	}

	public static function examineLoggedQueries()
	{
		foreach (self::$explainList as $q => $a) {
			self::explainQuery($q, $a);
		}
	}

	private static function explainQuery($query, $data)
	{
		$args = array_shift($data);
		$slow = false;
		$veryslow = false;
		foreach ($data as &$ts) {
			if ($ts > 0.004) {
				$slow = true;
				if ($ts > 0.015) {
					$ts = "[$ts]";
					$veryslow = true;
				}
			}
		}
		if (!$slow)
			return;
		unset($ts);
		$res = self::simpleQuery('EXPLAIN ' . $query, $args, true);
		if ($res === false)
			return;
		$rows = $res->fetchAll(PDO::FETCH_ASSOC);
		if (empty($rows))
			return;
		$log = $veryslow;
		$lens = array();
		foreach (array_keys($rows[0]) as $key) {
			$lens[$key] = strlen($key);
		}
		foreach ($rows as $row) {
			if (!$log && $row['rows'] > 20 && preg_match('/filesort|temporary/i', $row['Extra'])) {
				$log = true;
			}
			foreach ($row as $key => $col) {
				$l = strlen($col);
				if ($l > $lens[$key]) {
					$lens[$key] = $l;
				}
			}
		}
		if (!$log)
			return;
		error_log('Possible slow query: ' . $query);
		error_log('Times: ' . implode(', ', $data));
		$border = $head = '';
		foreach ($lens as $key => $len) {
			$border .= '+' . str_repeat('-', $len + 2);
			$head .= '| ' . str_pad($key, $len) . ' ';
		}
		$border .= '+';
		$head .= '|';
		error_log("\n" . $border . "\n" . $head . "\n" . $border);
		foreach ($rows as $row) {
			$line = '';
			foreach ($lens as $key => $len) {
				$line .= '| '. str_pad($row[$key], $len) . ' ';
			}
			error_log($line . "|");
		}
		error_log($border);
	}

	/**
	 * Convert nested array argument to multiple arguments.
	 * If you have:
	 * $query = 'SELECT * FROM tbl WHERE bcol = :bool AND col IN (:list)
	 * $args = ( 'bool' => 1, 'list' => ('foo', 'bar') )
	 * it results in:
	 * $query = '...WHERE bcol = :bool AND col IN (:list_0, :list_1)
	 * $args = ( 'bool' => 1, 'list_0' => 'foo', 'list_1' => 'bar' )
	 *
	 * @param string $query sql query string
	 * @param array $args query arguments
	 */
	private static function handleArrayArgument(&$query, &$args)
	{
		$again = false;
		foreach (array_keys($args) as $key) {
			if (is_numeric($key) || $key === '?')
				continue;
			if (is_array($args[$key])) {
				if (empty($args[$key])) {
					// Empty list - what to do? We try to generate a query string that will not yield any result
					$args[$key] = 'asdf' . mt_rand(0,PHP_INT_MAX) . mt_rand(0,PHP_INT_MAX)
							. mt_rand(0,PHP_INT_MAX) . '@' . microtime(true);
					continue;
				}
				$newkey = $key;
				if ($newkey{0} !== ':') {
					$newkey = ":$newkey";
				}
				$new = array();
				foreach ($args[$key] as $subIndex => $sub) {
					if (is_array($sub)) {
						$new[] = '(' . $newkey . '_' . $subIndex . ')';
						$again = true;
					} else {
						$new[] = $newkey . '_' . $subIndex;
					}
					$args[$newkey . '_' . $subIndex] = $sub;
				}
				unset($args[$key]);
				$new = implode(',', $new);
				$query = preg_replace('/' . $newkey . '\b/', $new, $query);
			}
		}
		if ($again) {
			self::handleArrayArgument($query, $args);
		}
	}

	/**
	 * Simply calls PDO::prepare and returns the PDOStatement.
	 * You must call PDOStatement::execute manually on it.
	 */
	public static function prepare($query)
	{
		self::init();
		self::$queryCount += 1; // Cannot know actual count
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

	public static function getQueryCount()
	{
		return self::$queryCount;
	}

	public static function getQueryTime()
	{
		return self::$queryTime;
	}

}
