<?php

class Dictionary
{

	/**
	 * @var string[] Array of languages, numeric index, two letter CC as values
	 */
	private static $languages = false;
	/**
	 * @var array Array of languages, numeric index, values are ['name' => 'Language Name', 'cc' => 'xx']
	 */
	private static $languagesLong = false;
	private static $stringCache = array();

	public static function init()
	{
		self::$languages = array();
		foreach (glob('lang/??', GLOB_ONLYDIR) as $lang) {
			if (!file_exists($lang . '/name.txt') && !file_exists($lang . '/flag.png'))
				continue;
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

	/**
	 * Get complete key=>value list for given module, file, language
	 *
	 * @param string $module Module name
	 * @param string $file Dictionary name
	 * @param string|false $lang Language CC, false === current language
	 * @return array assoc array mapping language tags to the translated strings
	 */
	public static function getArray($module, $file, $lang = false)
	{
		if ($lang === false)
			$lang = LANG;
		$path = Util::safePath("modules/{$module}/lang/{$lang}/{$file}.json");
		if (isset(self::$stringCache[$path]))
			return self::$stringCache[$path];
		if (!file_exists($path))
			return array();
		$content = file_get_contents($path);
		if ($content === false) { // File does not exist for language
			$content = '[]';
		}
		$json = json_decode($content, true);
		if (!is_array($json)) {
			$json = array();
		}
		return self::$stringCache[$path] = $json;
	}

	/**
	 * Translate a tag from a dictionary of a module. The current
	 * language will be used.
	 *
	 * @param string $moduleId The module in question
	 * @param string $file Dictionary name
	 * @param string $tag Tag name
	 * @param bool $returnTagOnMissing If true, the tag name enclosed in {{}} will be returned if the tag does not exist
	 * @return string|false The requested tag's translation, or false if not found and $returnTagOnMissing === false
	 */
	public static function translateFileModule($moduleId, $file, $tag, $returnTagOnMissing = false)
	{
		$strings = self::getArray($moduleId, $file);
		if (!isset($strings[$tag])) {
			if ($returnTagOnMissing) {
				return '{{' . $tag . '}}';
			}
			return false;
		}
		return $strings[$tag];
	}

	/**
	 * Translate a tag from a dictionary of the current module, using the current language.
	 *
	 * @param string $file Dictionary name
	 * @param string $tag Tag name
	 * @param bool $returnTagOnMissing If true, the tag name enclosed in {{}} will be returned if the tag does not exist
	 * @return string|false The requested tag's translation, or false if not found and $returnTagOnMissing === false
	 */
	public static function translateFile($file, $tag, $returnTagOnMissing = false)
	{
		if (!class_exists('Page') || Page::getModule() === false)
			return false; // We have no page - return false for now, as we're most likely running in api or install mode
		return self::translateFileModule(Page::getModule()->getIdentifier(), $file, $tag, $returnTagOnMissing);
	}

	/**
	 * Translate a tag from the current module's default dictionary, using the current language.
	 *
	 * @param string $tag Tag name
	 * @param bool $returnTagOnMissing If true, the tag name enclosed in {{}} will be returned if the tag does not exist
	 * @return string|false The requested tag's translation, or false if not found and $returnTagOnMissing === false
	 */
	public static function translate($tag, $returnTagOnMissing = false)
	{
		$string = self::translateFile('module', $tag);
		if ($string !== false)
			return $string;
		$string = self::translateFileModule('main', 'global-tags', $tag);
		if ($string !== false || !$returnTagOnMissing)
			return $string;
		return '{{' . $tag . '}}';
	}

	/**
	 * Translate the given message id, reading the given module's messages dictionary.
	 *
	 * @param string $module Module the message belongs to
	 * @param string $id Message id
	 * @return string|false
	 */
	public static function getMessage($module, $id)
	{
		$string = self::translateFileModule($module, 'messages', $id);
		if ($string === false) {
			return "($id) ({{0}}, {{1}}, {{2}}, {{3}})";
		}
		return $string;
	}

	/**
	 * Get translation of the given category.
	 *
	 * @param string $category
	 * @return string Category name, or some generic fallback to the given category id
	 */
	public static function getCategoryName($category)
	{
		if ($category === false) {
			return 'No Category';
		}
		if (!preg_match('/^(\w+)\.(.*)$/', $category, $out)) {
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
	 * @param boolean $withName true = return assoc array containing cc and name of all languages;
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
				} else {
					$name = false;
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

	/**
	 * Get name of language matching given language CC.
	 * Default to the CC if the language isn't known.
	 *
	 * @param string $langCC
	 * @return string
	 */
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

	/**
	 * Get an <img> tag for the given language. If there is no flag image,
	 * fall back to generating a .badge with the CC.
	 * If long mode is requested, returns the name of the language right next
	 * to the image, otherwise, it is just added as the title attribute.
	 *
	 * @param $caption bool with caption next to <img>
	 * @param $langCC string Language cc to get flag code for - defaults to current language
	 * @retrun string html code of img tag for language
	 */
	public static function getFlagHtml($caption = false, $langCC = false)
	{
		if ($langCC === false) {
			$langCC = LANG;
		}
		$flag = "lang/$langCC/flag.png";
		$name = htmlspecialchars(self::getLanguageName($langCC));
		if (file_exists($flag)) {
			$img = '<img alt="' . $name . '" title="' . $name . '" src="' . $flag . '"> ';
			if ($caption) {
				$img .= $name;
			}
		} else {
			$img = '<div class="badge" title="' . $name . '">' . $langCC . '</div>';
		}
		return $img;
	}

}

Dictionary::init();

