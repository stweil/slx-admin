<?php

require_once('inc/util.inc.php');

require_once('Mustache/Autoloader.php');
Mustache_Autoloader::register();

/**
 * HTML rendering helper class
 */
Render::init();

class Render
{

	/**
	 * @var Mustache_Engine
	 */
	private static $mustache = false;
	private static $body = '';
	private static $header = '';
	private static $dashboard = false;
	private static $footer = '';
	private static $title = '';
	private static $templateCache = array();
	private static $tags = array();

	public static function init()
	{
		if (self::$mustache !== false)
			Util::traceError('Called Render::init() twice!');
		$options = array();
		$tmp = '/tmp/bwlp-cache';
		$dir = is_dir($tmp);
		if (!$dir) {
			@mkdir($tmp, 0755, false);
		}
		if (($dir || is_dir($tmp)) && is_writable($tmp)) {
			$options['cache'] = $tmp;
		}
		self::$mustache = new Mustache_Engine($options);
	}

	private static function cssEsc($str)
	{
		return str_replace(array('"', '&', '<', '>'), array('\\000022', '\\000026', '\\00003c', '\\00003e'), $str);
	}

	/**
	 * Output the buffered, generated page
	 */
	public static function output()
	{
		Header('Content-Type: text/html; charset=utf-8');
		/* @var $modules Module[] */
		$modules = array_reverse(Module::getActivated());
		$pageModule = Page::getModule();
		$title = Property::get('page-title-prefix', '');
		$bgcolor = Property::get('logo-background', '');
		if (!empty($bgcolor) || !empty($title)) {
			self::$header .= '<style type="text/css">' . "\n";
			if (!empty($bgcolor)) {
				$fgcolor = self::readableColor($bgcolor);
				self::$header .= ".navbar-header{background-color:$bgcolor}a.navbar-brand{color:$fgcolor!important}";
			}
			if (!empty($title)) {
				self::$header .= '#navbar-sub:after{content:"' . self::cssEsc($title) . '";margin:0}';
			}
			self::$header .= "\n</style>";
		}
		ob_start('ob_gzhandler');
		echo
		'<!DOCTYPE html>
	<html lang="' . LANG . '">
		<head>
			<title>', $title, self::$title, (defined('CONFIG_PRODUCT_NAME_LONG') ? CONFIG_PRODUCT_NAME_LONG : 'OpenSLX Admin'), '</title>
			<meta charset="utf-8"> 
			<meta http-equiv="X-UA-Compatible" content="IE=edge">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<!-- Bootstrap -->
			<link href="style/bootstrap.min.css" rel="stylesheet" media="screen">
	';
		// Include any module specific styles
		foreach ($modules as $module) {
			$files = $module->getCss();
			foreach ($files as $file) {
				echo '<link href="', $module->getDir(), '/', $file, '" rel="stylesheet" media="screen">';
			}
		}
		echo '	
			<link href="style/default.css" rel="stylesheet" media="screen">
			<script type="text/javascript">
			var TOKEN = "' . Session::get('token') . '";
			var LANG = "'  . LANG . '";
			</script>
	',
		self::$header
		,
		'	</head>
		<body>
	',
		(self::$dashboard !== false ? self::parse('main-menu', self::$dashboard, 'main') : ''),
		'<div class="main" id="mainpage"><div class="container-fluid">
	',
		self::$body
		,
		'</div></div>
		<script src="script/jquery.js"></script>
		<script src="script/bootstrap.min.js"></script>
		<script src="script/taskmanager.js"></script>
		<script src="script/slx-fixes.js"></script>
	';
		foreach ($modules as $module) {
			$files = $module->getScripts();
			foreach ($files as $file) {
				echo '<script src="', $module->getDir(), '/', $file, '"></script>';
			}
		}
		echo
		self::$footer
		,
		'</body>
		</html>'
		;
		ob_end_flush();
	}

	/**
	 * Set the page title (title-tag)
	 */
	public static function setTitle($title, $override = true)
	{
		if (!$override && !empty(self::$title))
			return;
		self::$title = $title . ' - ';
	}

	/**
	 * Add raw html data to the header-section of the generated page
	 */
	public static function addHeader($html)
	{
		self::$header .= $html . "\n";
	}

	/**
	 * Add raw html data to the footer-section of the generated page (right before the closing body tag)
	 */
	public static function addFooter($html)
	{
		self::$footer .= $html . "\n";
	}

	/**
	 * Add the given template to the output, using the given params for placeholders in the template
	 */
	public static function addTemplate($template, $params = false, $module = false)
	{
		self::$body .= self::parse($template, $params, $module);
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
		), 'main');
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
	 * @param string $template name of template, relative to templates/, without .html extension
	 * @param array $params tags to render into template
	 * @param string $module name of module to load template from; defaults to currently active module
	 * @return string Rendered template
	 */
	public static function parse($template, $params = false, $module = false, $lang = false)
	{
		if ($module === false && class_exists('Page')) {
			$module = Page::getModule()->getIdentifier();
		}
		// Load html snippet
		$html = self::getTemplate($template, $module);
		if ($html === false) {
			return '<h3>Template ' . htmlspecialchars($template) . '</h3>' . nl2br(htmlspecialchars(print_r($params, true))) . '<hr>';
		}
		if (!is_array($params)) {
			$params = array();
		}
		// Now find all language tags in this array
		if (preg_match_all('/{{\s*(lang_.+?)\s*}}/', $html, $out) > 0) {
			$dictionary = Dictionary::getArray($module, 'template-tags', $lang);
			$fallback = false;
			foreach ($out[1] as $tag) {
				if ($fallback === false && empty($dictionary[$tag])) {
					$fallback = true; // Fallback to general dictionary of main module
					$dictionary = $dictionary + Dictionary::getArray('main', 'global-tags');
				}
				// Add untranslated strings to the dictionary, so their tag is seen in the rendered page
				if (empty($dictionary[$tag])) {
					$dictionary[$tag] = '{{' . $tag . '}}';
				}
			}
			$params = $params + $dictionary;
		}
		// Always add token to parameter list
		$params['token'] = Session::get('token');
		if (defined('LANG')) {
			// Likewise, add currently selected language (its two letter code) to params
			$params['current_lang'] = LANG;
		}
		// Add desired password field type
		$params['password_type'] = Property::getPasswordFieldType();
		// Branding
		$params['product_name'] = defined('CONFIG_PRODUCT_NAME') ? CONFIG_PRODUCT_NAME : 'OpenSLX';
		// Return rendered html
		return self::$mustache->render($html, $params);
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
	private static function getTemplate($template, $module)
	{
		$id = "$template/$module";
		if (isset(self::$templateCache[$id])) {
			return self::$templateCache[$id];
		}
		// Load from disk
		$data = @file_get_contents('modules/' . $module . '/templates/' . $template . '.html');
		self::$templateCache[$id] =& $data;
		return $data;
	}

	/**
	 * Create the dashboard menu
	 */
	public static function setDashboard($params)
	{
		self::$dashboard = $params;
	}

	public static function readableColor($hex) {
		if (strlen($hex) <= 4) {
			$cnt = 1;
		} else {
			$cnt = 2;
		}
		if (preg_match('/^#?([a-f0-9]{'.$cnt.'})([a-f0-9]{'.$cnt.'})([a-f0-9]{'.$cnt.'})$/i', $hex, $out) != 1)
			return '#000';
		$chans = array();
		$f = ($cnt === 1 ? 17 : 1);
		for ($i = 1; $i <= 3; ++$i) {
			$out[$i] = (hexdec($out[$i]) * $f);
			$chans[] = $out[$i] ^ 0x80;
		}
		$b = (255 - (0.299 * $out[1] + 0.587 * $out[2] + 0.114 * $out[3])) * 2;
		return sprintf("#%02x%02x%02x", ($chans[0] + $b) / 3, ($chans[1] + $b) / 3, ($chans[2] + $b) / 3);
	}

}
