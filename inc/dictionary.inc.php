<?php

class Dictionary
{

	private static $languages = false;
	private static $languagesLong = false;
	private static $stringCache = array();

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

	public static function getArray($module, $path, $lang = false)
	{
		if ($lang === false)
			$lang = LANG;
		$file = Util::safePath("modules/{$module}/lang/{$lang}/{$path}.json");
		if (isset(self::$stringCache[$file]))
			return self::$stringCache[$file];
		$content = @file_get_contents($file);
		if ($content === false) { // File does not exist for language
			$content = '[]';
			error_log("getArray called for non-existent $file");
		}
		$json = json_decode($content, true);
		if (!is_array($json)) {
			$json = array();
		}
		return self::$stringCache[$file] = $json;
	}

	public static function translateFileModule($moduleId, $path, $tag)
	{
		$strings = self::getArray($moduleId, $path);
		if (!isset($strings[$tag])) {
			return false;
		}
		return $strings[$tag];
	}
	
	public static function translateFile($path, $tag)
	{
		return self::translateFileModule(Page::getModule()->getIdentifier(), $path, $tag);
	}

	public static function translate($tag)
	{
		$string = self::translateFile('module', $tag);
		if ($string !== false)
			return $string;
		return self::translateFileModule('main', 'global-tags', $tag);
	}

	public static function getMessage($module, $id)
	{
		$string = self::translateFileModule($module, 'messages', $id);
		if ($string === false) {
			return "($id) ({{0}}, {{1}}, {{2}}, {{3}})";
		}
		return $string;
	}
	
	public static function getCategoryName($category)
	{
		if ($category === false) {
			return 'No Category';
		}
		if (!preg_match('/^(\w+)\.(\w+)$/', $category, $out)) {
			return 'Invalid Category ID format: ' . $category;
		}
		$string = self::translateFileModule($out[1], 'categories', $out[2]);
		if ($string === false) {
			return "!!{$category}!!";
		}
		return $string;
	}

	/**
	 * Get all supported languages as array.
	 *
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
					$name = file_get_contents("lang/$lang/name.txt");
				}
				if (!isset($name) || $name === false) {
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
	
	public static function getLanguageName($langCC)
	{
		if (file_exists("lang/$langCC/name.txt")) {
			$name = file_get_contents("lang/$langCC/name.txt");
		}
		if (!isset($name) || $name === false) {
			$name = $langCC;
		}
		return $name;
	}

}

Dictionary::init();

