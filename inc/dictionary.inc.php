<?php

class Dictionary
{

	private static $messageArray = false;
	private static $languages = false;
	private static $languagesLong = false;
	private static $stringCache = array();
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

	public static function getArrayTemplate($template, $module = false, $lang = false)
	{
		return self::getArray($module . "/" . $template, $lang);
	}

	public static function getArray($module, $lang = false, $isMessage = false)
	{
		if ($lang === false)
			$lang = LANG;
		if(!$isMessage)
			$file = Util::safePath("lang/" . $lang . "/modules/" . $module . ".json");
		else
			$file = Util::safePath("lang/" . $lang . "/" . $module . ".json");

		if (isset(self::$stringCache[$file]))
			return self::$stringCache[$file];
		$content = @file_get_contents($file);
		if ($content === false) {// File does not exist for language {
			return array();
		}
		$json = json_decode($content, true);
		if (!is_array($json))
			return array();
		return self::$stringCache[$file] = $json;
	}

	public static function translate($section, $string = false)
	{
		if ($string === false) {
			// Fallback: General "hardcoded" messages
			$string = $section;
			if (self::$hardcodedMessages === false)
				self::$hardcodedMessages = json_decode(file_get_contents("lang/" . LANG . "/messages-hardcoded.json"), true);
			if (!isset(self::$hardcodedMessages[$string]))
				return "(missing: $string :missing)";
			return self::$hardcodedMessages[$string];
		}
		$strings = self::getArray($section, false, true);
		if (!isset($strings[$string])) {
			return "(missing: '$string' in '$section')";
		}
		return $strings[$string];
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
	 * @param boolean $withName true = return assoc array containinc cc and name of all languages;
	 *		false = regular array containing only the ccs
	 * @return array List of languages
	 */
	public static function getLanguages($withName = false)
	{
		if (!$withName)
			return self::$languages;
		if (self::$languagesLong === false) {
			self::$languagesLong = array();
			foreach (self::$languages as $lang) {
				if (file_exists("lang/$lang/name.txt")) {
					$name = @file_get_contents("lang/$lang/name.txt");
				} else {
					$name = $lang;
				}
				self::$languagesLong[] = array(
					'cc' => $lang,
					'name' => $name
				);
			}
		}
		return self::$languagesLong;
	}

}

Dictionary::init();

