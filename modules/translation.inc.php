<?php

class Page_Translation extends Page
{

	/**
	 * The pages where you can administrate the website translations
	 * @var string|boolean holds the target template
	 * @var string|boolean used to choose which page to load
	 * @var string|boolean used check if there should be an update
	 * @var string|boolean used check if there should be a deletion
	 * @var array|boolean holds the tags of the selected template
	 */
	private $template = false;
	private $page = false;
	private $update = false;
	private $delete = false;
	private $tags = false;

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}

		$this->template = Request::get('template');
		$this->page = Request::get('page');
		$this->delete = Request::get('delete');
		$this->update = Request::post('update');
	}

	protected function doRender()
	{
		//calls the update function
		if ($this->update)
			$this->updateJson();
		//calls the tag deletion function
		if ($this->delete && $this->template)
			$this->deleteTag($this->template, $this->delete);

		//load the page accordingly to the link
		switch ($this->page) {
			case 'messages':
				//renders the message edition page
				Render::addTemplate('translation/messages', array(
					'msgs' => $this->initMsg(false),
					'msgsHC' => $this->initMsg(true)
				));
				break;
			case 'templates':
				$this->template = Util::safePath($this->template);
				if ($this->template === false) {
					Message::addError('invalid-path');
					Util::redirect('?do=Translation');
				}
				//renders the tag edition page
				if ($this->templateAnalysis($this->template)) {
					$langs = array();
					foreach (Dictionary::getLanguages() as $lang) {
						$langs[] = array('lang' => $lang);
					}
					Render::addTemplate('translation/template', array(
						'template' => 'templates/' . $this->template,
						'langs' => $langs,
						'tags' => $this->tags
					));
					break;
				}
			default:
				//renders the template selection page
				Render::addTemplate('translation/_page', array(
					'table' => $this->initTable(),
				));
		}
	}

	/**
	 * Load the main table with all the website's templates and it's informations
	 * @return array with the templates' information
	 */
	private function initTable()
	{
		$table = array();

		//loads every template
		$files = $this->listTemplates();
		//loads every json from each language
		$de = $this->listJson('de/templates/');
		$en = $this->listJson('en/templates/');
		$pt = $this->listJson('pt/templates/');

		//checks the JSON tags from every language
		foreach ($files as $key => $value) {
			// Don't list templates without lang tags
			$tmp = $this->checkJson($de[$key], 'de');
			if ($tmp === false) // TODO: Pretty solution
				continue;
			$table[] = array(
				'template' => $value,
				'link' => $key,
				'de' => $tmp,
				'en' => $this->checkJson($en[$key], 'en'),
				'pt' => $this->checkJson($pt[$key], 'pt')
			);
		}
		sort($table);
		return $table;
	}

	/**
	 * Finds and returns all the website's templates
	 * @return array
	 */
	private function listTemplates()
	{
		$files = array();
		$dir = 'templates/';
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($objects as $name => $object) {
			if (substr($name, -5)  === '.html') {
				$key = substr($name, strlen($dir), -5);
				$files[$key] = substr($name, strlen($dir));
			}
		}
		return $files;
	}

	/**
	 * Finds and returns all the JSON files from a selected language
	 * @param string the selected language (abbreviated)
	 * @return array all the JSON files from the language
	 */
	private function listJson($lang)
	{
		$json = array();
		$dir = 'lang/' . $lang;
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($objects as $name => $object) {
			if (substr($name, -5) === '.json') {
				$key = str_replace($dir, '', $name);
				$key = substr($key, 0, -5);
				$json[$key] = $key;
			}
		}
		return $json;
	}

	/**
	 * Finds and returns all PHP files of slxadmin
	 * @return array of all php files
	 */
	private function listPhp()
	{
		$php = array();
		$dir = '.';
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($objects as $name => $object) {
			if (substr($name, -4) === '.php') {
				$php[] = $name;
			}
		}
		return $php;
	}

	/**
	 * Checks the JSON tags from a template
	 * @param string the template's path
	 * @param string the selected language
	 * @return string|boolean the information about the JSON tags, false if template has no lang-tags
	 */
	private function checkJson($path, $lang)
	{
		//if there was not a valid template's path
		if (!$path) {
			return "JSON file is missing";
		}
		//loads a template and find all its tags
		$htmlTemplate = @file_get_contents("templates/$path.html");
		if (preg_match_all('/{{lang_(.*?)}}/s', $htmlTemplate, $matches) == 0)
			return false;
		$htmlCount = count(array_unique($matches[1]));

		//initialize the count variables
		$matchCount = 0;
		$unusedCount = 0;

		//loads the JSON tags and count the matches
		$json = Dictionary::getArrayTemplate($path, $lang);
		foreach ($json as $key => $value) {
			if ($key != 'lang') {
				if (!in_array(preg_replace('/^lang_/', '', $key), $matches[1])) {
					$unusedCount++;
				} else if ($value != '') {
					$matchCount++;
				}
			}
		}
		$diff = $htmlCount - $matchCount;

		//build the return string
		$str = "";
		if ($diff == 0 && $unusedCount == 0)
			$str .= "OK";
		if ($diff > 0)
			$str .= $diff . " JSON tag(s) are missing";
		if ($diff > 0 && $unusedCount > 0)
			$str .= "<br>";
		if ($unusedCount > 0)
			$str .= $unusedCount . " JSON tag(s) are not being used";
		return $str;
	}

	/**
	 * Builds the template page with the tags from its template and its JSON file
	 * @param string the template's path
	 * @param string the selected language
	 * @return string the information about the JSON tags
	 */
	private function templateAnalysis($path)
	{
		$path = "templates/$path";
		$templateFile = "$path.html";
		//checks if the template is valid
		if (!file_exists($templateFile)) {
			Message::addError('invalid-template', $templateFile);
			return false;
		}

		// All languages
		$langArray = Dictionary::getLanguages();

		//finds every mustache tag within the html template
		$htmlTemplate = file_get_contents($templateFile);
		preg_match_all('/{{(lang_.*?)}}/s', $htmlTemplate, $matches);
		$tags = array();
		foreach ($matches[1] as $tagName) {
			$tags[$tagName] = array('tag' => $tagName);
			foreach ($langArray as $lang) {
				$tags[$tagName]['langs'][$lang]['lang'] = $lang;
				$tags[$tagName]['missing'] = count($langArray);
			}
		}

		//finds every JSON tag withing the JSON language files
		foreach ($langArray as $lang) {
			$jsonTags = Dictionary::getArray($path, $lang);
			if (!is_array($jsonTags))
				continue;
			foreach ($jsonTags as $tag => $translation) {
				if (substr($tag, 0, 5) === 'lang_') {
					$tags[$tag]['langs'][$lang]['translation'] = $translation;
					$tags[$tag]['tag'] = $tag;
					if (!isset($tags[$tag]['missing']))
						$tags[$tag]['missing'] = 0;
					if (!empty($translation))
						$tags[$tag]['missing']--;
				}
			}
		}
		// Finally remove $lang from the keys so mustache will iterate over them via {{#..}}
		foreach ($tags as &$tag) {
			$tag['langs'] = array_values($tag['langs']);
			$tag['class'] = $this->getTagColor($tag['missing']);
		}
		$this->tags = array_values($tags);

		return true;
	}

	/**
	 * Loads the content of a JSON tag
	 * @param string the JSON's path
	 * @param string the selected tag
	 * @param string the specified language
	 * @return string the tag's content
	 */
	private function getJsonTag($path, $tag, $lang)
	{
		$json = Dictionary::getArray($path, $lang);
		if (is_array($json) && isset($json[$tag])) {
			return $json[$tag];
		}
		return '';
	}

	/**
	 * Change the color of the table line according to the tag status
	 * @param string the JSON's path
	 * @param string the selected tag
	 * @return string the css class of the line
	 */
	private function getTagColor($missingCount)
	{
		//return danger in case the tag is not found in the template
		if ($missingCount < 0)
			return 'danger';

		//return warning in case at least one of the tag's values is empty
		if ($missingCount > 0)
			return 'warning';
		//if it's ok don't change the class
		return '';
	}

	/**
	 * Updates a JSON file with it's new tags or/and tags values
	 */
	private function updateJson()
	{
		$langArray = Dictionary::getLanguages();
		foreach ($langArray as $lang) {
			$json[$lang] = array();
		}

		//find the tag requests to change the file
		foreach ($_REQUEST as $key => $value) {
			$str = explode('#', $key, 3);
			if (count($str) !== 3)
				continue;
			$pre = $str[0];
			$lang = $str[1];
			$tag = $str[2];
			if ($pre !== 'lang')
				continue;
			if (!isset($json[$lang])) {
				Message::addWarning('i18n-invalid-lang', $lang);
				continue;
			}
			if (empty($tag)) {
				Message::addWarning('i18n-empty-tag');
				continue;
			}
			if ($tag !== 'newtag') {
				$json[$lang][$tag] = $value;
			} else {
				$json[$lang][$_REQUEST['newtag']] = $value;
			}
		}

		// JSON_PRETTY_PRINT is only available starting with php 5.4.0.... Use upgradephp's json_encode
		require_once('inc/up_json_encode.php');

		//saves the new values on the file
		foreach ($json as $key => $array) {
			$path = Util::safePath('lang/' . $key . '/' . Request::post('path') . '.json');
			if ($path === false) {
				Message::addError('invalid-path');
				Util::redirect('?do=Translation');
			}
			ksort($array); // Sort by key, so the diff on the output is cleaner
			error_log("Converting " . print_r($array, true));
			$json = up_json_encode($array, JSON_PRETTY_PRINT); // Also for better diffability of the json files, we pretty print
			error_log("Result: $json");
			//exits the function in case the action was unsuccessful
			if (@file_put_contents($path, $json) === false) {
				Message::addError('invalid-template');
				return;
			}
		}
		Message::addSuccess('updated-tags');
	}

	/**
	 * Load the main table with all the website's messages or hardcoded messages
	 * @var boolean choose between hardcoded and non-hardcoded messages
	 * @return array with the selected messages
	 */
	private function initMsg($isHardcoded)
	{
		$msgs = array();
		// Get all php files, so we can find all strings that need to be translated
		$php = $this->listPhp();
		$tags = array();
		//chooses the path and regex
		if ($isHardcoded) {
			$path = 'messages-hardcoded';
			$expr = '/Dictionary\s*::\s*translate\s*\(\s*[\'"](.*?)[\'"]\s*[\)\,]/i';
		} else {
			$path = 'messages';
			$expr = '/Message\s*::\s*add\w+\s*\(\s*[\'"](.*?)[\'"]\s*[\)\,]/i';
		}
		// Now find all tags in all php files. Only works for literal usage, not something like $foo = 'bar'; Dictionary::translate($foo);
		foreach ($php as $file) {
			$content = @file_get_contents($file);
			if ($content === false || preg_match_all($expr, $content, $out) < 1)
				continue;
			foreach ($out[1] as $id) {
				if (!isset($tags[$id]))
					$tags[$id] = 0; // TODO: Display usage count next to it, even better would be list of php files it appears in, so a translator can get context
				$tags[$id]++;
			}
		}
		ksort($tags);
		foreach ($tags as $tag => $usageCount) {
			$msgs[] = array(
				'tag' => $tag,
				'de' => $this->getJsonTag($path, $tag, 'de/'), // TODO: Hardcoded language list, use Dictionary::getLanguages()
				'en' => $this->getJsonTag($path, $tag, 'en/'),
				'pt' => $this->getJsonTag($path, $tag, 'pt/')
			);
		}
		return $msgs;
	}

	/**
	 * Delete a specific JSON tag from a JSON files
	 * @var string the JSON's file path
	 * @var the JSON tag to be deleted
	 * @return boolean if the action was not successful
	 */
	private function deleteTag($path, $tag)
	{
		//delete the tag from every language file
		$langArray = Dictionary::getLanguages();
		foreach ($langArray as $lang) {
			$json = Dictionary::getArrayTemplate($path, $lang);
			unset($json[$tag]);
			unset($json['lang']);
			$result = file_put_contents('lang/' . $lang . '/' . $path . '.json', json_encode($json));
			//add warning and exit in case the action was unsuccessful
			if ($result === false) {
				Message::addWarning('unsuccessful-action');
				return false;
			}
		}
		Message::addSuccess('deleted-tag');
	}

}
