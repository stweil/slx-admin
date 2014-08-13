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

		if (Request::get('template')) {
			$this->template = Request::get('template');
		}

		if (Request::get('page')) {
			$this->page = Request::get('page');
		}

		if (Request::get('delete')) {
			$this->delete = Request::get('delete');
		}

		if (Request::post('update')) {
			$this->update = Request::post('update');
		}
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
					'token' => Session::get('token'),
					'msgs' => $this->initMsg(false),
					'msgsHC' => $this->initMsg(true)
				));
				break;
			case 'templates':
				//renders the tag edition page
				if ($this->templateAnalysis($this->template)) {
					Render::addTemplate('translation/template', array(
						'token' => Session::get('token'),
						'template' => $this->template,
						'tags' => $this->tags
					));
					break;
				}
			default:
				//renders the template selection page
				Render::addTemplate('translation/_page', array(
					'token' => Session::get('token'),
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
		$de = $this->listJson('de/');
		$en = $this->listJson('en/');
		$pt = $this->listJson('pt/');

		//checks the JSON tags from every language
		foreach ($files as $key => $value) {
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
			if (array_pop(explode('.', $name)) === 'html') {
				$key = str_replace($dir, '', $name);
				$key = str_replace('.html', '', $key);
				$files[$key] = $name;
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
			if (array_pop(explode('.', $name)) === 'json') {
				$key = str_replace($dir, '', $name);
				$key = str_replace('.json', '', $key);
				$json[$key] = $key;
			}
		}
		return $json;
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
		$htmlTemplate = file_get_contents('templates/' . $path . '.html');
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
		//checks if the template is valid
		if (!file_exists('templates/' . $path . '.html')) {
			Message::addError('invalid-template');
			return false;
		}

		//finds every mustache tag within the html template
		$htmlTemplate = file_get_contents('templates/' . $path . '.html');
		preg_match_all('/{{(lang_.*?)}}/s', $htmlTemplate, $matches);
		$tags = array_flip($matches[1]);

		//finds every JSON tag withing the JSON language files
		$langArray = Dictionary::getLanguages();
		$json = array();
		foreach ($langArray as $lang) {
			$jsonTags = Dictionary::getArrayTemplate($path, $lang);
			if (!is_array($jsonTags))
				continue;
			foreach (array_keys($jsonTags) as $key) {
				if (substr($key, 5) === 'lang_')
					$json[$key] = 123;
			}
		}
		//remove unused tag
		unset($json['lang']);
		//merges the arrays to keep the unique tags
		$test = array_merge($json, $tags);
		//echo "TEST:\n";
		//print_r($test);
		//loads the content of every JSON tag from the specified language
		foreach (array_keys($test) as $tag) {
			$this->tags[] = array(
				'tag' => $tag,
				'de' => $this->checkJsonTag($path, $tag, 'de/'),
				'en' => $this->checkJsonTag($path, $tag, 'en/'),
				'pt' => $this->checkJsonTag($path, $tag, 'pt/'),
				'class' => $this->checkJsonTags($path, $tag)
			);
		}
		//echo "TAGS:\n";
		//print_r($this->tags);

		return true;
	}

	/**
	 * Loads the content of a JSON tag
	 * @param string the JSON's path
	 * @param string the selected tag
	 * @param string the specified language
	 * @return string the tag's content
	 */
	private function checkJsonTag($path, $tag, $lang)
	{
		$json = Dictionary::getArrayTemplate($path, $lang);
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
	private function checkJsonTags($path, $tag)
	{
		//return danger in case the tag is not found in the template
		$htmlTemplate = file_get_contents('templates/' . $path . '.html');
		$htmlCount = substr_count($htmlTemplate, $tag);
		if ($htmlCount < 1)
			return "danger";

		//return warning in case at least one of the tag's values is empty
		$langArray = array('de/', 'en/', 'pt/');
		foreach ($langArray as $lang) {
			if (($json = Dictionary::getArrayTemplate($path, $lang))) {
				if (!isset($json[$tag]) || $json[$tag] == '')
					return 'warning';
			}
		}
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
				error_log("Setting \$json['$lang']['$tag'] = '$value';");
			} else {
				$json[$lang][$_REQUEST['newtag']] = $value;
			}
		}

		// JSON_PRETTY_PRINT is only available starting with php 5.4.0.... Use upgradephp's json_encode
		require_once('inc/up_json_encode.php');

		//saves the new values on the file
		foreach ($json as $key => $array) {
			$path = 'lang/' . $key . '/' . $_POST['path'] . '.json'; // TODO: Wtf? Unvalidated user input -> filesystem access!
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
		//chooses the path
		$path = 'messages';
		if ($isHardcoded) {
			$path = 'messages-hardcoded';
		}
		//loads the content of every JSON tag from the message file
		$json = Dictionary::getArrayTemplate($path, LANG);
		foreach ($json as $key => $array) {
			if ($key != 'lang')
				$msgs[] = array(
					'tag' => $key,
					'de' => $this->checkJsonTag($path, $key, 'de/'), // TODO: Hardcoded language list, use Dictionary::getLanguages()
					'en' => $this->checkJsonTag($path, $key, 'en/'),
					'pt' => $this->checkJsonTag($path, $key, 'pt/')
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
		$langArray = unserialize(SITE_LANGUAGES);
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
