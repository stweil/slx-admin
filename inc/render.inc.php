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
	private static $dashboard = '';
	private static $footer = '';
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
		$page = strtolower($_GET['do']);
		if(User::isLoggedIn())
		self::createDashboard($page);
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
			<link href="style/bootstrap-switch.css" rel="stylesheet" media="screen">
			
			<script src="script/bootstrap-switch.js"></script>
			<script type="text/javascript">
			var TOKEN = "' . Session::get('token') . '";
			</script>
	',
		self::$header
		,
		'	</head>
		<body>
		<div class="container-fluid" id="mainpage">
			<div class="row">
	',
		self::$dashboard
		,
		self::$body
		,
		'	</div>
		</div>
		<script src="script/jquery.js"></script>
		<script src="script/bootstrap.min.js"></script>
		<script src="script/taskmanager.js"></script>
	',
		self::$footer
		,
		'</body>
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
	 * Add raw html data to the footer-section of the generated page (right before the closing body tag)
	 */
	public static function addFooter($html)
	{
		self::$footer .= $html . "\n";
	}

	/**
	 * Add given js script file from the script directory to the header
	 *
	 * @param string $file file name of script
	 */
	public static function addScriptTop($file)
	{
		self::addHeader('<script src="script/' . $file . '.js"></script>');
	}

	/**
	 * Add given js script file from the script directory to the bottom
	 *
	 * @param string $file file name of script
	 */
	public static function addScriptBottom($file)
	{
		self::addFooter('<script src="script/' . $file . '.js"></script>');
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
	 * @return string Rendered template
	 */
	public static function parse($template, $params = false, $module = false)
	{
		// Load html snippet
		$html = self::getTemplate($template,$module);
		if ($html === false) {
			return '<h3>Template ' . htmlspecialchars($template) . '</h3>' . nl2br(htmlspecialchars(print_r($params, true))) . '<hr>';
		}
		// Get all translated strings for this template
		if($module === false){
			$module = strtolower(empty($_REQUEST['do']) ? 'main' : $_REQUEST['do']);
		}
		$dictionary = Dictionary::getArrayTemplate($template, $module);
		
		// Now find all language tags in this array
		preg_match_all('/{{(lang_.+?)}}/', $html, $out);
		foreach ($out[1] as $tag) {
			// Add untranslated strings to the dictionary, so their tag is seen in the rendered page
			if (empty($dictionary[$tag]))
				$dictionary[$tag] = '{{' . $tag . '}}';
		}
		// Always add token to parameter list
		if (is_array($params) || $params === false || is_null($params))
			$params['token'] = Session::get('token');
		// Likewise, add currently selected language ( its two letter code) to params
		$params['current_lang'] = LANG;
		// Add desired password field type
		$params['password_type'] = Property::getPasswordFieldType();
		// Return rendered html
		return self::$mustache->render($html, array_merge($dictionary,$params));
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
	private static function getTemplate($template, $module = false)
	{
		if (isset(self::$templateCache[$template])) {
			return self::$templateCache[$template];
		}
		// Select current module
		if(!$module){
			$module = strtolower(empty($_REQUEST['do']) ? 'Main' : $_REQUEST['do']);
		}
		// Load from disk
		$data = @file_get_contents('modules/' . $module . '/templates/' . $template . '.html');
		if ($data === false)
			$data = '<b>Non-existent template ' . $template . ' requested!</b>';
		self::$templateCache[$template] = & $data;
		return $data;
	}

	/**
	 * Create the dashboard menu
	 */
	private static function createDashboard($page)
	{
		// Check all required modules
		$requiredModules = array('adduser','main','session','translation','usermanagement');
		$notFound = '';
		foreach ($requiredModules as $module) {
			if(!is_dir('modules/' . $module . '/')){
				$notFound .= '\'' . $module . '\'  ';
			}
		}
		if(strlen($notFound) > 0){
			Util::traceError('At least one required module was not found: ' . $notFound);
		}else{
			$modules = array_diff(scandir('modules/'), array('..', '.'));
			$categories = array();
			foreach ($modules as $module) {
				$json = json_decode(file_get_contents("modules/" . $module . "/config.json"),true);
				$categories[$json['category']][] = $module;
			}
			unset($categories['hidden']);
			self::$dashboard = '<div class="col-sm-3 col-md-2 sidebar">';
			foreach ($categories as $cat => $modules) {
				self::$dashboard .= '<div class="dash-header"></span> <span class="glyphicon glyphicon-' . self::getGlyphicon($cat) 
					. '" aria-hidden="true"></span> ' . Dictionary::translate('lang_' . $cat) . '</div>';
				self::$dashboard .= '<ul class="nav nav-sidebar">';
				foreach ($modules as $module) {
					self::$dashboard .= '<li class="' . (($page == $module) ? 'active' : '') 
            					. '"><a href="?do=' . ucfirst($module) . '"> ' . (Dictionary::translate('lang_' . $module)) . '</a></li>';
				}
				self::$dashboard .= '</ul>';
			}
			self::$dashboard .= '</div>  <div class="col-sm-9 col-sm-offset-3 col-md-10 col-md-offset-2 main">';
		}
	}

	/**
	* get categories glyph icons
	*/
	private static function getGlyphicon($category){
		return json_decode(file_get_contents("style/categories.json"),true)[$category];
	}

}
