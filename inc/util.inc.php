<?php

class Util
{
	private static $redirectParams = array();

	/**
	 * Displays an error message and stops script execution.
	 * If CONFIG_DEBUG is true, it will also dump a stack trace
	 * and all globally defined variables.
	 * (As this might reveal sensistive data you should never enable it in production)
	 */
	public static function traceError($message)
	{
		if ((defined('API') && API) || (defined('AJAX') && AJAX) || php_sapi_name() === 'cli') {
			error_log('API ERROR: ' . $message);
			error_log(self::formatBacktracePlain(debug_backtrace()));
		}
		if (php_sapi_name() === 'cli') {
			// Don't spam HTML when invoked via cli, above error_log should have gone to stdout/stderr
			exit(1);
		}
		Header('HTTP/1.1 500 Internal Server Error');
		if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'html') === false ) {
			Header('Content-Type: text/plain; charset=utf-8');
			echo 'API ERROR: ', $message, "\n", self::formatBacktracePlain(debug_backtrace());
			exit(0);
		}
		Header('Content-Type: text/html; charset=utf-8');
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><style>', "\n",
			".arg { color: red; background: white; }\n",
			"h1 a { color: inherit; text-decoration: inherit; font-weight: inherit; }\n",
			'</style><title>Fatal Error</title></head><body>';
		echo '<h1>Flagrant <a href="https://www.youtube.com/watch?v=7rrZ-sA4FQc&t=2m2s" target="_blank">S</a>ystem error</h1>';
		echo "<h2>Message</h2><pre>$message</pre>";
		if (strpos($message, 'Database') !== false) {
			echo '<div><a href="install.php">Try running database setup</a></div>';
		}
		echo "<br><br>";
		if (defined('CONFIG_DEBUG') && CONFIG_DEBUG) {
			global $SLX_ERRORS;
			if (!empty($SLX_ERRORS)) {
				echo '<h2>PHP Errors</h2><pre>';
				foreach ($SLX_ERRORS as $error) {
					echo htmlspecialchars("{$error['errstr']} ({$error['errfile']}:{$error['errline']}\n");
				}
				echo '</pre>';
			}
			echo "<h2>Stack Trace</h2>";
			echo '<pre>', self::formatBacktraceHtml(debug_backtrace()), '</pre>';
			echo "<h2>Globals</h2><pre>";
			echo htmlspecialchars(print_r($GLOBALS, true));
			echo '</pre>';
		} else {
			echo <<<SADFACE
<pre>
________________________¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶________
____________________¶¶¶___________________¶¶¶¶_____
________________¶¶¶_________________________¶¶¶¶___
______________¶¶______________________________¶¶¶__
___________¶¶¶_________________________________¶¶¶_
_________¶¶_____________________________________¶¶¶
________¶¶_________¶¶¶¶¶___________¶¶¶¶¶_________¶¶
______¶¶__________¶¶¶¶¶¶__________¶¶¶¶¶¶_________¶¶
_____¶¶___________¶¶¶¶____________¶¶¶¶___________¶¶
____¶¶___________________________________________¶¶
___¶¶___________________________________________¶¶_
__¶¶____________________¶¶¶¶____________________¶¶_
_¶¶_______________¶¶¶¶¶¶¶¶¶¶¶¶¶¶¶______________¶¶__
_¶¶____________¶¶¶¶___________¶¶¶¶¶___________¶¶___
¶¶¶_________¶¶¶__________________¶¶__________¶¶____
¶¶_________¶______________________¶¶________¶¶_____
¶¶¶______¶________________________¶¶_______¶¶______
¶¶¶_____¶_________________________¶¶_____¶¶________
_¶¶¶___________________________________¶¶__________
__¶¶¶________________________________¶¶____________
___¶¶¶____________________________¶¶_______________
____¶¶¶¶______________________¶¶¶__________________
_______¶¶¶¶¶_____________¶¶¶¶¶_____________________
</pre>
SADFACE;
		}
		echo '</body></html>';
		exit(0);
	}

	private static function formatArgument($arg, $expandArray = true)
	{
		if (is_string($arg)) {
			$arg = "'$arg'";
		} elseif (is_object($arg)) {
			$arg = 'instanceof ' . get_class($arg);
		} elseif (is_array($arg)) {
			if ($expandArray && count($arg) < 20) {
				$expanded = '';
				foreach ($arg as $key => $value) {
					if (!empty($expanded)) {
						$expanded .= ', ';
					}
					$expanded .= $key . ': ' . self::formatArgument($value, false);
					if (strlen($expanded) > 200)
						break;
				}
				if (strlen($expanded) <= 200)
					return '[' . $expanded . ']';
			}
			$arg = 'Array(' . count($arg) . ')';
		}
		return $arg;
	}

	public static function formatBacktraceHtml($trace)
	{
		$output = '';
		foreach ($trace as $idx => $line) {
			$args = array();
			foreach ($line['args'] as $arg) {
				$arg = self::formatArgument($arg);
				$args[] = '<span class="arg">' . htmlspecialchars($arg) . '</span>';
			}
			$frame = str_pad('#' . $idx, 3, ' ', STR_PAD_LEFT);
			$function = htmlspecialchars($line['function']);
			$args = implode(', ', $args);
			$file = preg_replace('~(/[^/]+)$~', '<b>$1</b>', htmlspecialchars($line['file']));
			// Add line
			$output .= $frame . ' ' . $function . '<b>(</b>'
				. $args . '<b>)</b>' . ' @ <i>' . $file . '</i>:' . $line['line'] . "\n";
		}
		return $output;
	}

	public static function formatBacktracePlain($trace)
	{
		$output = '';
		foreach ($trace as $idx => $line) {
			$args = array();
			foreach ($line['args'] as $arg) {
				$args[] = self::formatArgument($arg);
			}
			$frame = str_pad('#' . $idx, 3, ' ', STR_PAD_LEFT);
			$args = implode(', ', $args);
			// Add line
			$output .= "\n" . $frame . ' ' . $line['function'] . '('
				. $args . ')' . ' @ ' . $line['file'] . ':' . $line['line'];
		}
		return $output;
	}

	/**
	 * Redirects the user via a '302 Moved' header.
	 * An active session will be saved, any messages that haven't
	 * been displayed yet will be appended to the redirect.
	 * @param string|false $location Location to redirect to. "false" to redirect to same URL (useful after POSTs)
	 * @param bool $preferRedirectPost if true, use the value from $_POST['redirect'] instead of $location
	 */
	public static function redirect($location = false, $preferRedirectPost = false)
	{
		if ($location === false) {
			$location = preg_replace('/([&?])message\[\]\=[^&]*/', '\1', $_SERVER['REQUEST_URI']);
		}
		Session::save();
		$messages = Message::toRequest();
		if ($preferRedirectPost
			&& ($redirect = Request::post('redirect', false, 'string')) !== false
			&& !preg_match(',^([0-9a-zA-Z_+\-]+:|//),', $redirect) /* no uri scheme, no server */) {
			$location = $redirect;
		}
		if (!empty($messages)) {
			if (strpos($location, '?') === false) {
				$location .= '?' . $messages;
			} else {
				$location .= '&' . $messages;
			}
		}
		if (!empty(self::$redirectParams)) {
			if (strpos($location, '?') === false) {
				$location .= '?' . implode('&', self::$redirectParams);
			} else {
				$location .= '&' . implode('&', self::$redirectParams);
			}
		}
		if (CONFIG_DEBUG) {
			global $global_start;
			$duration = microtime(true) - $global_start;
			error_log('Redirect: ' . round($duration, 3) . 's, '
				. Database::getQueryCount() . ' queries, '
				. round(Database::getQueryTime(), 3) . 's query time total');
		}
		Header('Location: ' . $location);
		exit(0);
	}
	
	public static function addRedirectParam($key, $value)
	{
		self::$redirectParams[] = $key . '=' . urlencode($value);
	}

	/**
	 * Verify the user's token that protects against CSRF.
	 * If the user is logged in and there is no token variable set in
	 * the request, or the submitted token does not match the user's
	 * token, this function will return false and display an error.
	 * If the token matches, or the user is not logged in, it will return true.
	 */
	public static function verifyToken()
	{
		if (!User::isLoggedIn() && Session::get('token') === false)
			return true;
		if (isset($_REQUEST['token']) && Session::get('token') === $_REQUEST['token'])
			return true;
		Message::addError('main.token');
		return false;
	}

	/**
	 * Simple markup "rendering":
	 * *word* is bold
	 * /word/ is italics
	 * _word_ is underlined
	 * \n is line break
	 */
	public static function markup($string)
	{
		$string = htmlspecialchars($string);
		$string = preg_replace('#(^|[\n \-_/\.])\*(.+?)\*($|[ \-_/\.\!\?,:])#is', '$1<b>$2</b>$3', $string);
		$string = preg_replace('#(^|[\n \-\*/\.])_(.+?)_($|[ \-\*/\.\!\?,:])#is', '$1<u>$2</u>$3', $string);
		$string = preg_replace('#(^|[\n \-_\*\.])/(.+?)/($|[ \-_\*\.\!\?,:])#is', '$1<i>$2</i>$3', $string);
		return nl2br($string);
	}

	/**
	 * Convert given number to human readable file size string.
	 * Will append Bytes, KiB, etc. depending on magnitude of number.
	 * 
	 * @param float|int $bytes numeric value of the filesize to make readable
	 * @param int $decimals number of decimals to show, -1 for automatic
	 * @param int $shift how many units to skip, i.e. if you pass in KiB or MiB
	 * @return string human readable string representing the given file size
	 */
	public static function readableFileSize($bytes, $decimals = -1, $shift = 0)
	{
		$bytes = round($bytes);
		static $sz = array('Byte', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
		$factor = (int)floor((strlen($bytes) - 1) / 3);
		if ($factor === 0) {
			$decimals = 0;
		} else {
			$bytes = $bytes / pow(1024, $factor);
			if ($decimals === -1) {
				$decimals = 2 - floor(strlen((int)$bytes) - 1);
			}
		}
		return sprintf("%.{$decimals}f", $bytes) . "\xe2\x80\x89" . $sz[$factor + $shift];
	}

	public static function sanitizeFilename($name)
	{
		return preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $name);
	}

	public static function safePath($path, $prefix = '')
	{
		if (empty($path))
			return false;
		$path = trim($path);
		if ($path{0} == '/' || preg_match('/[\x00-\x19\?\*]/', $path))
			return false;
		if (strpos($path, '..') !== false)
			return false;
		if (substr($path, 0, 2) !== './')
			$path = "./$path";
		if (empty($prefix))
			return $path;
		if (substr($prefix, 0, 2) !== './')
			$prefix = "./$prefix";
		if (substr($path, 0, strlen($prefix)) !== $prefix)
			return false;
		return $path;
	}

	/**
	 * Create human readable error description from a $_FILES[<..>]['error'] code
	 * 
	 * @param int $code the code to turn into an error description
	 * @return string the error description
	 */
	public static function uploadErrorString($code)
	{
		switch ($code) {
			case UPLOAD_ERR_INI_SIZE:
				$message = "The uploaded file exceeds the upload_max_filesize directive in php.ini";
				break;
			case UPLOAD_ERR_FORM_SIZE:
				$message = "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form";
				break;
			case UPLOAD_ERR_PARTIAL:
				$message = "The uploaded file was only partially uploaded";
				break;
			case UPLOAD_ERR_NO_FILE:
				$message = "No file was uploaded";
				break;
			case UPLOAD_ERR_NO_TMP_DIR:
				$message = "Missing a temporary folder";
				break;
			case UPLOAD_ERR_CANT_WRITE:
				$message = "Failed to write file to disk";
				break;
			case UPLOAD_ERR_EXTENSION:
				$message = "File upload stopped by extension";
				break;

			default:
				$message = "Unknown upload error";
				break;
		}
		return $message;
	}

	/**
	 * Is given string a public ipv4 address?
	 *
	 * @param string $ip_addr input to check
	 * @return boolean true iff $ip_addr is a valid public ipv4 address
	 */
	public static function isPublicIpv4($ip_addr)
	{
		if (!preg_match("/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/", $ip_addr))
			return false;

		$parts = explode(".", $ip_addr);
		foreach ($parts as $part) {
			if (!is_numeric($part) || $part > 255 || $part < 0)
				return false;
		}

		if ($parts[0] == 0 || $parts[0] == 10 || $parts[0] == 127 || ($parts[0] > 223 && $parts[0] < 240))
			return false;
		if (($parts[0] == 192 && $parts[1] == 168) || ($parts[0] == 169 && $parts[1] == 254))
			return false;
		if ($parts[0] == 172 && $parts[1] > 15 && $parts[1] < 32)
			return false;

		return true;
	}

	/**
	 * Check whether $arrax contains all keys given in $keyList
	 * @param array $array An array
	 * @param array $keyList A list of strings which must all be valid keys in $array
	 * @return boolean
	 */
	public static function hasAllKeys($array, $keyList)
	{
		if (!is_array($array))
			return false;
		foreach ($keyList as $key) {
			if (!isset($array[$key]))
				return false;
		}
		return true;
	}
	
	/**
	 * Send a file to user for download.
	 *
	 * @param string $file path of local file
	 * @param string $name name of file to send to user agent
	 * @param boolean $delete delete the file when done?
	 * @return boolean false: file could not be opened.
	 *		true: error while reading the file
	 *		- on success, the function does not return
	 */
	public static function sendFile($file, $name, $delete = false)
	{
		while ((@ob_get_level()) > 0)
			@ob_end_clean();
		$fh = fopen($file, 'rb');
		if ($fh === false) {
			Message::addError('main.error-read', $file);
			return false;
		}
		Header('Content-Type: application/octet-stream', true);
		Header('Content-Disposition: attachment; filename=' . str_replace(array(' ', '=', ',', '/', '\\', ':', '?'), '_', iconv('UTF-8', 'ASCII//TRANSLIT', $name)));
		Header('Content-Length: ' . filesize($file));
		fpassthru($fh);
		fclose($fh);
		if ($delete) {
			unlink($file);
		}
		exit(0);
	}

	/**
	 * Return a binary string of given length, containing
	 * random bytes. If $secure is given, only methods of
	 * obtaining cryptographically strong random bytes will
	 * be used, otherwise, weaker methods might be used.
	 *
	 * @param int $length number of bytes to return
	 * @param bool $secure true = only use strong random sources
	 * @return string|bool string of requested length, false on error
	 */
	public static function randomBytes($length, $secure = true)
	{
		if (function_exists('random_bytes')) {
			try {
				return random_bytes($length);
			} catch (Exception $e) {
				// Continue below
			}
		}
		if ($secure) {
			if (function_exists('openssl_random_pseudo_bytes')) {
				$bytes = openssl_random_pseudo_bytes($length, $ok);
				if ($bytes !== false && $ok) {
					return $bytes;
				}
			}
			$file = '/dev/random';
		} else {
			$file = '/dev/urandom';
		}
		$fh = @fopen($file, 'r');
		if ($fh !== false) {
			$bytes = fread($fh, $length);
			while ($bytes !== false && strlen($bytes) < $length) {
				$new = fread($fh, $length - strlen($bytes));
				if ($new === false) {
					$bytes = false;
					break;
				}
				$bytes .= $new;
			}
			fclose($fh);
			if ($bytes !== false) {
				return $bytes;
			}
		}
		if ($secure) {
			return false;
		}
		$bytes = '';
		while ($length > 0) {
			$bytes .= chr(mt_rand(0, 255));
		}
		return $bytes;
	}

	/**
	 * @return string a random UUID, v4.
	 */
	public static function randomUuid()
	{
		$b = unpack('h8a/h4b/h12c', self::randomBytes(12));
		return sprintf('%08s-%04s-%04x-%04x-%012s',

			// 32 bits for "time_low"
			$b['a'],

			// 16 bits for "time_mid"
			$b['b'],

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand(0, 0x0fff) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand(0, 0x3fff) | 0x8000,

			// 48 bits for "node"
			$b['c']
		);
	}

	/**
	 * Transform timestamp to easily readable string.
	 * The format depends on how far the timestamp lies in the past.
	 * @param int $ts unix timestamp
	 * @return string human readable representation
	 */
	public static function prettyTime($ts)
	{
		settype($ts, 'int');
		if ($ts === 0)
			return '???';
		static $TODAY = false, $ETODAY = false, $YESTERDAY = false, $YEARCUTOFF = false;
		if (!$ETODAY) $ETODAY = strtotime('today 23:59:59');
		if ($ts > $ETODAY) // TODO: Do we need strings for future too?
			return date('d.m.Y H:i', $ts);
		if (!$TODAY) $TODAY = strtotime('today 0:00');
		if ($ts >= $TODAY)
			return Dictionary::translate('lang_today') . ' ' . date('H:i', $ts);
		if (!$YESTERDAY) $YESTERDAY = strtotime('yesterday 0:00');
		if ($ts >= $YESTERDAY)
			return Dictionary::translate('lang_yesterday') . ' ' . date('H:i', $ts);
		if (!$YEARCUTOFF) $YEARCUTOFF = min(strtotime('-3 month'), strtotime('this year 1/1'));
		if ($ts >= $YEARCUTOFF)
			return date('d.m. H:i', $ts);
		return date('d.m.Y', $ts);
	}

	/**
	 * Return localized strings for yes or no depending on $bool
	 * @param bool $bool Input to evaluate
	 * @return string Yes or No, in user's selected language
	 */
	public static function boolToString($bool)
	{
		if ($bool)
			return Dictionary::translate('lang_yes', true);
		return Dictionary::translate('lang_no', true);
	}

	/**
	 * Format a duration, in seconds, into a readable string.
	 * @param int $seconds The number to format
	 * @param int $showSecs whether to show seconds, or rather cut after minutes
	 * @return string
	 */
	public static function formatDuration($seconds, $showSecs = true)
	{
		settype($seconds, 'int');
		static $UNITS = ['y' => 31536000, 'mon' => 2592000, 'd' => 86400];
		$parts = [];
		foreach ($UNITS as $k => $v) {
			if ($seconds < $v)
				continue;
			$n = floor($seconds / $v);
			$seconds -= $n * $v;
			$parts[] = $n. $k;
		}
		return implode(' ', $parts) . ' ' . gmdate($showSecs ? 'H:i:s' : 'H:i', $seconds);
	}

	/**
	 * Properly clear a cookie from the user's browser.
	 * This recursively wipes it from the current script's path. There
	 * was a weird problem where firefox would keep sending a cookie with
	 * path /slx-admin/ but trying to delete it from /slx-admin, which php's
	 * setcookie automatically sends by default, did not clear it.
	 * @param string $name cookie name
	 */
	public static function clearCookie($name)
	{
		$parts = explode('/', $_SERVER['SCRIPT_NAME']);
		$path = '';
		foreach ($parts as $part) {
			$path .= $part;
			setcookie($name, '', 0, $path);
			$path .= '/';
			setcookie($name, '', 0, $path);
		}
	}

}
