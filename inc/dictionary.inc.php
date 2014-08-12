<?php

class Dictionary
{

	private static $messageArray;
	private static $languages;

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
		self::$messageArray = json_decode(file_get_contents("lang/" . LANG . "/messages.json"), true);
	}

	public static function getArrayTemplate($template, $lang = false)
	{
		if ($lang === false)
			$lang = LANG;
		$file = "lang/" . $lang . "/" . $template . ".json";
		$content = @file_get_contents($file);
		if ($content === false)
			Util::traceError("Could not find language file $template for language $lang");
		$language = array('lang' => $lang);
		$json = json_decode($content, true);
		if (!is_array($json))
			return $language;
		return array_merge($language, $json);
	}

	public static function translate($string)
	{
		$hardcoded = json_decode(file_get_contents("lang/" . LANG . "/messages-hardcoded.json"), true);
		return $hardcoded[$string];
	}

	public static function getMessages()
	{
		return self::$messageArray;
	}

	public static function getLanguages()
	{
		return self::$languages;
	}

}

Dictionary::init();
