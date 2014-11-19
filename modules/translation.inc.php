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

		if (Request::post('update')) {
			$this->updateJson();
			Util::redirect('?do=Translation');
		}
		if (Request::post('delete')) {
			$this->deleteTag(Request::post('path'), Request::post('delete'));
			Util::redirect('?do=Translation'); // TODO: Ajax post for delete so we stay on the page
		}

		$this->template = Request::get('template');
		$this->page = Request::get('page');
	}

	protected function doRender()
	{
		$langs = Dictionary::getLanguages(true);

		//load the page accordingly to the link
		switch ($this->page) {
			case 'messages':
				//renders the message edit page
				Render::addTemplate('translation/edit', array(
					'path' => 'messages',
					'langs' => $langs,
					'tags' => $this->loadMessageEditArray()
				));
				break;
			case 'hardcoded':
				//renders the hardcoded messages edit page
				Render::addTemplate('translation/edit', array(
					'path' => 'messages-hardcoded',
					'langs' => $langs,
					'tags' => $this->loadHardcodedStringEditArray()
				));
				break;
			case 'settings':
				//renders the settings related edit page
				Render::addTemplate('translation/edit', array(
					'path' => 'settings/cat_setting',
					'langs' => $langs,
					'tags' => $this->loadCategoriesArray()
				));
				Render::addTemplate('translation/edit', array(
					'path' => 'settings/setting',
					'langs' => $langs,
					'tags' => $this->loadSettingsArray()
				));
				break;
			case 'template':
				$this->template = Util::safePath($this->template);
				if ($this->template === false) {
					Message::addError('invalid-path');
					Util::redirect('?do=Translation');
				}
				//renders the tag edition page
				Render::addTemplate('translation/edit', array(
					'path' => 'templates/' . $this->template,
					'langs' => $langs,
					'tags' => $this->loadTemplateEditArray($this->template)
				));
				break;
			case 'templates':
				//renders the template selection page
				Render::addTemplate('translation/template-list', array(
					'table' => $this->loadTemplatesList(),
				));
				break;
			default:
				//renders main page with selection of what part to edit
				Render::addTemplate('translation/_page');
		}
	}

	/**
	 * Load the main table with all the website's templates and it's informations
	 * @return array with the templates' information
	 */
	private function loadTemplatesList()
	{
		$table = array();

		//loads every template
		$files = $this->listTemplates();
		$langs = Dictionary::getLanguages(true);

		//checks the JSON tags from every language
		foreach ($files as $file) {
			$tags = $this->loadTemplateTags($file['path']);
			// Don't list templates without lang tags
			if (empty($tags))
				continue;
			$msgs = '';
			foreach ($langs as $lang) {
				$msg = $this->checkJson($file['path'], $lang['cc'], $tags);
				if (!empty($msg))
					$msgs .= "<div><span class='badge'>{$lang['name']}:</span>$msg</div>";
			}
			if (empty($msgs))
				$msgs = 'OK';
			$table[] = array(
				'template' => $file['name'],
				'link' => $file['name'],
				'status' => $msgs
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
			if (substr($name, -5) === '.html') {
				$files[] = array(
					'path' => substr($name, 0, -5),
					'name' => substr($name, strlen($dir), -5)
				);
			}
		}
		return $files;
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
	 * @param string tags that should be in the json file
	 * @return string|boolean the information about the JSON tags, false if template has no lang-tags
	 */
	private function checkJson($path, $lang, $expectedTags)
	{
		//if there was not a valid template's path
		if (!$path) {
			return "Translation missing";
		}
		// How many tags do we expect in the translation
		$htmlCount = count($expectedTags);

		//initialize the count variables
		$matchCount = 0;
		$unusedCount = 0;

		//loads the JSON tags and count the matches
		$json = Dictionary::getArray($path, $lang);
		//return print_r($json) . "\nvs\n" . print_r($expectedTags);
		foreach ($json as $key => $value) {
			if (!in_array($key, $expectedTags)) {
				$unusedCount++;
			} else if (!empty($value)) {
				$matchCount++;
			}
		}
		$diff = $htmlCount - $matchCount;

		if ($diff == 0 && $unusedCount == 0)
			return '';
		//build the return string
		$str = "";
		if ($diff > 0)
			$str .= $diff . " JSON tag(s) are missing";
		if ($diff > 0 && $unusedCount > 0)
			$str .= "<br>";
		if ($unusedCount > 0)
			$str .= $unusedCount . " JSON tag(s) are not being used";
		return $str;
	}

	/**
	 * Get array to pass to edit page with all the tags and translations for the given template
	 * @param string $path the template's path
	 * @return array the information about the JSON tags
	 */
	private function loadTemplateEditArray($path)
	{
		$path = "templates/$path";
		$tags = $this->loadTemplateTags($path);
		if ($tags === false)
			return false;
		return $this->buildTranslationTable($path, $tags);
	}

	/**
	 * Load array of tags used in given template.
	 * @param string $path the path of the template, relative to templates/, without .html extension.
	 * @return array all tags in template
	 */
	private function loadTemplateTags($path)
	{
		$templateFile = "$path.html";
		//checks if the template is valid
		if (!file_exists($templateFile)) {
			Message::addError('invalid-template', $templateFile);
			return false;
		}

		//finds every mustache tag within the html template
		$htmlTemplate = file_get_contents($templateFile);
		preg_match_all('/{{(lang_.*?)}}/s', $htmlTemplate, $matches);
		if (!isset($matches[1]) || !is_array($matches[1]))
			return array();
		return array_unique($matches[1]);
	}

	/**
	 * Load array of tags and translations of all messages
	 * @return array the information about the JSON tags
	 */
	private function loadMessageEditArray()
	{
		$tags = $this->loadTagsFromPhp('/Message\s*::\s*add\w+\s*\(\s*[\'"](.*?)[\'"]\s*[\)\,]/i');
		if ($tags === false)
			return false;
		return $this->buildTranslationTable('messages', $tags);
	}

	/**
	 * Load array of tags and translations of all strings found in the php files.
	 * @return array the information about the JSON tags
	 */
	private function loadHardcodedStringEditArray()
	{
		$tags = $this->loadTagsFromPhp('/Dictionary\s*::\s*translate\s*\(\s*[\'"](.*?)[\'"]\s*\)/i');
		if ($tags === false)
			return false;
		return $this->buildTranslationTable('messages-hardcoded', $tags);
	}

	/**
	 * Load array of tags used in all the php files, by given regexp. Capture group 1 should return
	 * the exact tag name.
	 * @param string $regexp regular expression matching all tags in capture group 1
	 * @return array of all tags found
	 */
	private function loadTagsFromPhp($regexp)
	{
		// Get all php files, so we can find all strings that need to be translated
		$php = $this->listPhp();
		$tags = array();
		// Now find all tags in all php files. Only works for literal usage, not something like $foo = 'bar'; Dictionary::translate($foo);
		foreach ($php as $file) {
			$content = @file_get_contents($file);
			if ($content === false || preg_match_all($regexp, $content, $out) < 1)
				continue;
			foreach ($out[1] as $id) {
				$tags[$id] = true;
			}
		}
		return array_keys($tags);
	}

	private function buildTranslationTable($path, $requiredTags = false)
	{
		// All languages
		$langArray = Dictionary::getLanguages();

		$tags = array();
		if ($requiredTags !== false) {
			foreach ($requiredTags as $tagName) {
				$tags[$tagName] = array('tag' => $tagName);
				foreach ($langArray as $lang) {
					$tags[$tagName]['langs'][$lang]['lang'] = $lang;
					$tags[$tagName]['missing'] = count($langArray);
				}
			}
		}

		//finds every JSON tag withing the JSON language files
		foreach ($langArray as $lang) {
			$jsonTags = Dictionary::getArray($path, $lang);
			if (!is_array($jsonTags))
				continue;
			foreach ($jsonTags as $tag => $translation) {
				$tags[$tag]['langs'][$lang]['translation'] = $translation;
				$tags[$tag]['langs'][$lang]['lang'] = $lang;
				if (strpos($translation, "\n") !== false)
					$tags[$tag]['langs'][$lang]['big'] = true;
				$tags[$tag]['tag'] = $tag;
				if (!isset($tags[$tag]['missing']))
					$tags[$tag]['missing'] = 0;
				if (!empty($translation))
					$tags[$tag]['missing'] --;
			}
		}
		// Finally remove $lang from the keys so mustache will iterate over them via {{#..}}
		foreach ($tags as &$tag) {
			$tag['langs'] = array_values($tag['langs']);
			if ($requiredTags !== false)
				$tag['class'] = $this->getTagColor($tag['missing']);
		}
		return array_values($tags);
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
		foreach ($_POST as $key => $value) {
			$str = explode('#', $key, 3);
			if (count($str) !== 3 || $str[0] !== 'lang')
				continue;
			$lang = $str[1];
			$tag = trim($str[2]);
			if (!isset($json[$lang])) {
				Message::addWarning('i18n-invalid-lang', $lang);
				continue;
			}
			if (empty($tag)) {
				Message::addWarning('i18n-empty-tag');
				continue;
			}
			$value = trim($value);
			if ($tag !== 'newtag') {
				if (empty($value)) {
					unset($json[$lang][$tag]);
				} else {
					$json[$lang][$tag] = $value;
				}
			} else {
				if (!empty($value)) // TODO: Error message if new tag's name collides with existing
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
			@mkdir(dirname($path), 0755, true);
			ksort($array); // Sort by key, so the diff on the output is cleaner
			$json = up_json_encode($array, JSON_PRETTY_PRINT); // Also for better diffability of the json files, we pretty print
			//exits the function in case the action was unsuccessful
			if (@file_put_contents($path, $json) === false) {
				Message::addError('invalid-template');
				return;
			}
		}
		Message::addSuccess('updated-tags');
	}

	/**
	 * Delete a specific JSON tag from a JSON files
	 * @var string the JSON's file path
	 * @var the JSON tag to be deleted
	 * @return boolean if the action was not successful
	 */
	private function deleteTag($path, $tag)
	{
		// JSON_PRETTY_PRINT is only available starting with php 5.4.0.... Use upgradephp's json_encode
		require_once('inc/up_json_encode.php');

		//delete the tag from every language file
		$langArray = Dictionary::getLanguages();
		foreach ($langArray as $lang) {
			$json = Dictionary::getArray($path, $lang);
			unset($json[$tag]);
			$result = file_put_contents('lang/' . $lang . '/' . $path . '.json', up_json_encode($json, JSON_PRETTY_PRINT));
			//add warning and exit in case the action was unsuccessful
			if ($result === false) {
				Message::addWarning('unsuccessful-action');
				return false;
			}
		}
		Message::addSuccess('deleted-tag');
	}
	
	/**
	 * Load all settings categories for editing.
	 * 
	 * @return array
	 */
	private function loadCategoriesArray()
	{
		$want = array();
		$res = Database::simpleQuery("SELECT catid FROM cat_setting ORDER BY catid ASC");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$want[] = 'cat_' . $row['catid'];
		}
		return $this->buildTranslationTable('settings/cat_setting', $want);
	}
	
	/**
	 * Load all settings categories for editing.
	 * 
	 * @return array
	 */
	private function loadSettingsArray()
	{
		$want = array();
		$res = Database::simpleQuery("SELECT setting FROM setting ORDER BY setting ASC");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$want[] = $row['setting'];
		}
		return $this->buildTranslationTable('settings/setting', $want);
	}

}
