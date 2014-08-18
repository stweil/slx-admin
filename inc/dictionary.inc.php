<?php

class Dictionary
{

	private static $messageArray = false;
	private static $languages;
	private static $templateCache = array();
	private static $hardcodedMessages = false;

	public static function init()
	{
		self::$languages = array();
		foreach (glob('lang/??', GLOB_ONLYDIR) as $lang) {
			$lang = basename($lang);
			if ($lang === '..')
				continue;
			self::$languages[] = $lang;
		}

		//Changes the language in case there is a request to
		$lang = Request::get('lang');
		if ($lang !== false && in_array($lang, self::$languages)) {
			setcookie('lang', $lang, time() + 60 * 60 * 24 * 30 * 12);
			$url = Request::get('url');
			if ($url === false && isset($_SERVER['HTTP_REFERER']))
				$url = $_SERVER['HTTP_REFERER'];
			if ($url === false)
				$url = '?do=Main';
			Util::redirect($url);
		}

		//Default language
		$language = 'en';

		if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], self::$languages)) {
			// Did user override language?
			$language = $_COOKIE['lang'];
		} else if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			$langs = preg_split('/[,\s]+/', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
			foreach ($langs as $lang) {
				$lang = substr($lang, 0, 2);
				if (in_array($lang, self::$languages)) {
					$language = $lang;
					break;
				}
			}
		}

		define('LANG', $language);
	}

	public static function getArrayTemplate($template, $lang = false)
	{
		return self::getArray('templates/' . $template, $lang);
	}

	public static function getArray($section, $lang = false)
	{
		if ($lang === false)
			$lang = LANG;
		$file = Util::safePath("lang/" . $lang . "/" . $section . ".json");
		if (isset(self::$templateCache[$file]))
			return self::$templateCache[$file];
		$language = array('lang' => $lang);
		$content = @file_get_contents($file);
		if ($content === false) // File does not exist for language
			return $language;
		$json = json_decode($content, true);
		if (!is_array($json))
			return $language;
		return self::$templateCache[$file] = array_merge($language, $json);
	}

	public static function translate($string)
	{
		if (self::$hardcodedMessages === false)
			self::$hardcodedMessages = json_decode(file_get_contents("lang/" . LANG . "/messages-hardcoded.json"), true);
		if (!isset(self::$hardcodedMessages[$string]))
			return "(missing: $string :missing)";
		return self::$hardcodedMessages[$string];
	}

	public static function getMessage($id)
	{
		if (self::$messageArray === false)
			self::$messageArray = json_decode(file_get_contents("lang/" . LANG . "/messages.json"), true);
		if (!isset(self::$messageArray[$id]))
			return "(missing: $id :missing)";
		return self::$messageArray[$id];
	}

	/**
	 * Get all supported languages as array
	 * @return array List of languages (2 char id)
	 */
	public static function getLanguages()
	{
		return self::$languages;
	}

}

Dictionary::init();

