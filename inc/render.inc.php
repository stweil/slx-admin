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
		if (self::$mustache !== false) Util::traceError('Called Render::init() twice!');
		self::$mustache = new Mustache_Engine;
	}

	/**
	 * Output the buffered, generated page
	 */
	public static function output()
	{
		Header('Content-Type: text/html; charset=utf-8');
		echo
	'<!DOCTYPE html>
	<html>
		<head>
			<title>', RENDER_DEFAULT_TITLE, self::$title, '</title>
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<!-- Bootstrap -->
			<link href="style/bootstrap.min.css" rel="stylesheet" media="screen">
			<link href="style/default.css" rel="stylesheet" media="screen">
	',
		self::$header
		,
	'	</head>
		<body>
		<div class="container">
	',
		self::$body
		,
	'	</div>
		<script src="script/jquery.js"></script>
		<script src="script/bootstrap.min.js"></script>
		<script src="script/custom.js"></script></body>
	</html>'
		;
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
		self::$body .= self::$mustache->render(self::getTemplate($template), $params);
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
		if (empty(self::$tags)) Util::traceError('Tried to close tag ' . $tag . ' when no open tags exist.');
		$last = array_pop(self::$tags);
		if ($last !== $tag) Util::traceError('Tried to close tag ' . $tag . ' when last opened tag was ' . $last);
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
		if ($data === false) $data = '<b>Non-existent template ' . $template . ' requested!</b>';
		self::$templateCache[$template] =& $data;
		return $data;
	}
}

