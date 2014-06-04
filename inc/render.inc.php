<?php

define('RENDER_DEFAULT_TITLE', 'OpenSLX Admin');

require_once('inc/util.inc.php');

require_once('Mustache/Autoloader.php');
Mustache_Autoloader::register();

/**
 * HTML rendering helper class
 */
Render::init();

class Render
{

	private static $mustache = false;
	private static $body = '';
	private static $header = '';
	private static $title = '';
	private static $templateCache = array();
	private static $tags = array();

	public static function init()
	{
		if (self::$mustache !== false)
			Util::traceError('Called Render::init() twice!');
		self::$mustache = new Mustache_Engine;
	}

	/**
	 * Output the buffered, generated page
	 */
	public static function output()
	{
		Header('Content-Type: text/html; charset=utf-8');
		$zip = isset($_SERVER['HTTP_ACCEPT_ENCODING']) && (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false);
		if ($zip)
			ob_start();
		echo
		'<!DOCTYPE html>
	<html>
		<head>
			<title>', RENDER_DEFAULT_TITLE, self::$title, '</title>
			<meta charset="utf-8"> 
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<!-- Bootstrap -->
			<link href="style/bootstrap.min.css" rel="stylesheet" media="screen">
			<link href="style/bootstrap-tagsinput.css" rel="stylesheet" media="screen">
			<link href="style/default.css" rel="stylesheet" media="screen">
			<script type="text/javascript">
			var TOKEN = "' . Session::get('token') . '";
			</script>
	',
		self::$header
		,
		'	</head>
		<body>
		<div class="container" id="mainpage">
	',
		self::$body
		,
		'	</div>
		<script src="script/jquery.js"></script>
		<script src="script/bootstrap.min.js"></script>
		<script src="script/bootstrap-tagsinput.min.js"></script>
		<script src="script/taskmanager.js"></script>
	</html>'
		;
		if ($zip) {
			Header('Content-Encoding: gzip');
			ob_implicit_flush(false);
			$gzip_contents = ob_get_contents();
			ob_end_clean();
			echo "\x1f\x8b\x08\x00\x00\x00\x00\x00";
			echo substr(gzcompress($gzip_contents, 5), 0, -4);
		}
	}

	/**
	 * Set the page title (title-tag)
	 */
	public static function setTitle($title)
	{
		self::$title = ' - ' . $title;
	}

	/**
	 * Add raw html data to the header-section of the generated page
	 */
	public static function addHeader($html)
	{
		self::$header .= $html . "\n";
	}

	/**
	 * Add the given template to the output, using the given params for placeholders in the template
	 */
	public static function addTemplate($template, $params = false)
	{
		self::$body .= self::parse($template, $params);
	}

	/**
	 * Add a dialog to the page output.
	 * 
	 * @param string $title Title of the dialog window
	 * @param boolean $next URL to next dialog step, or false to hide the next button
	 * @param string $template template used to fill the dialog body
	 * @param array $params parameters for rendering the body template
	 */
	public static function addDialog($title, $next, $template, $params = false)
	{
		self::addTemplate('dialog-generic', array(
			'title' => $title,
			'next' => $next,
			'body' => self::parse($template, $params)
		));
	}

	/**
	 * Add error message to page
	 */
	public static function addError($message)
	{
		self::addTemplate('messagebox-error', array('message' => $message));
	}

	/**
	 * Parse template with given params and return; do not add to body
	 */
	public static function parse($template, $params = false)
	{
		if (is_array($params))
			$params['token'] = Session::get('token');
		return self::$mustache->render(self::getTemplate($template), $params);
	}

	/**
	 * Open the given html tag, optionally adding the passed assoc array of params
	 */
	public static function openTag($tag, $params = false)
	{
		array_push(self::$tags, $tag);
		if (!is_array($params)) {
			self::$body .= '<' . $tag . '>';
		} else {
			self::$body .= '<' . $tag;
			foreach ($params as $key => $val) {
				self::$body .= ' ' . $key . '="' . htmlspecialchars($val) . '"';
			}
			self::$body .= '>';
		}
	}

	/**
	 * Close the given tag. Will check if it maches the tag last opened
	 */
	public static function closeTag($tag)
	{
		if (empty(self::$tags))
			Util::traceError('Tried to close tag ' . $tag . ' when no open tags exist.');
		$last = array_pop(self::$tags);
		if ($last !== $tag)
			Util::traceError('Tried to close tag ' . $tag . ' when last opened tag was ' . $last);
		self::$body .= '</' . $tag . '>';
	}

	/**
	 * Private helper: Load the given template and return it
	 */
	private static function getTemplate($template)
	{
		if (isset(self::$templateCache[$template])) {
			return self::$templateCache[$template];
		}
		// Load from disk
		$data = @file_get_contents('templates/' . $template . '.html');
		if ($data === false)
			$data = '<b>Non-existent template ' . $template . ' requested!</b>';
		self::$templateCache[$template] = & $data;
		return $data;
	}

}
