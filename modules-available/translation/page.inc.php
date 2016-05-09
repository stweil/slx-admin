<?php

/**
 * The pages where you can administrate the website translations
 */
class Page_Translation extends Page
{

	/**
	 * @var string holds the target template, or subsection for custom section
	 */
	private $subsection = false;
	
	/**
	 * @var \Module module being edited, false for module list
	 */
	private $module = false;

	/**
	 * @var string used to choose which page to load (mode of operation)
	 */
	private $section = false;
	
	/**
	 * @var string[] list of sections that are handled directly by this module
	 */
	private $builtInSections;
	
	/**
	 * @var array Custom module handler, if any, false otherwise
	 */
	private $customHandler = false;
	
	/**
	 * @var type Language being handled (if any in current step)
	 */
	private $destLang = false;
	
	/*
	 * 
	 */
	
	public function __construct()
	{
		$this->builtInSections = array('template', 'messages', 'custom');
	}
	
	private function isValidSection($section)
	{
		return in_array($section, $this->builtInSections);
	}
	
	private function loadCustomHandler($moduleName)
	{
		$path = 'modules/' . $moduleName . '/hooks/translation.inc.php';
		if (!file_exists($path))
			return false;
		$HANDLER = array();
		require $path;
		return $HANDLER;
	}
	
	/**
	 * Redirect to the closest matching page, as extracted from
	 * get/post parameters.
	 */
	private function redirect($level = 99)
	{
		$params = array('do' => 'translation');
		if ($level > 0 && $this->module !== false) {
			$params['module'] = $this->module->getIdentifier();
		}
		if ($level > 1 && $this->section !== false && $this->destLang !== false && in_array($this->destLang, Dictionary::getLanguages())) {
			$params['section'] = $this->section;
			$params['destlang'] = $this->destLang;
		}
		Util::redirect('?' . http_build_query($params));
	}

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}
		
		// Set up variables
		// Module to be edited
		$moduleName = Request::any('module', false, 'string');
		if ($moduleName !== false) {
			$this->module = Module::get($moduleName, true);
			if ($this->module === false) {
				Message::addError('main.no-such-module', $moduleName);
				$this->redirect();
			} elseif ($this->module->hasMissingDependencies()) {
				Message::addError('main.module-missing-deps', $moduleName);
				$this->redirect();
			}
			$this->customHandler = $this->loadCustomHandler($moduleName);
		}
		if ($this->module !== false) {
			// Section
			$sectionName = Request::any('section', false, 'string');
			if ($sectionName !== false) {
				if (!$this->isValidSection($sectionName)) {
					Message::addError('invalid-section', $sectionName);
					$this->redirect();
				}
			}
			$this->section = $sectionName;
		}
		// Subsection (being checked when used)
		$this->subsection = Request::any('subsection', false, 'string');
		// LANG (verify if needed)
		$this->destLang = Request::any('destlang', false, 'string');

		if (Request::post('update')) {
			$this->updateJson();
			$this->redirect(1);
		}
	}
	
	private function ensureValidDestLanguage()
	{
		if (!in_array($this->destLang, Dictionary::getLanguages())) {
			Message::addError('i18n-invalid-lang', $this->destLang);
			$this->redirect();
		}
	}

	protected function doRender()
	{
		$langs = Dictionary::getLanguages(true);
		
		// Overview (list of modules)
		if ($this->module === false) {
			$this->showListOfModules();
			return;
		}
		
		// One module - overview
		if ($this->section === false) {
			$this->showModule();
			return;
		}
		
		// Edit template string
		if ($this->section === 'template') {
			$this->ensureValidDestLanguage();
			$this->showTemplateEdit();
			return;
		}
		
		// Messugiz
		if ($this->section === 'messages') {
			$this->ensureValidDestLanguage();
			$this->showMessagesEdit();
			return;
		}

		//load the page accordingly to the link
		switch ($this->section) {
			case 'messages':
				//renders the message edit page
				Render::addTemplate('edit', array(
					'path' => 'messages',
					'langs' => $langs,
					'tags' => $this->loadMessageEditArray()
				));
				break;
			case 'hardcoded':
				//renders the hardcoded messages edit page
				Render::addTemplate('edit', array(
					'path' => 'messages-hardcoded',
					'langs' => $langs,
					'tags' => $this->loadHardcodedStringEditArray()
				));
				break;
			case 'settings':
				//renders the settings related edit page
				Render::addTemplate('edit', array(
					'path' => 'cat_setting',
					'langs' => $langs,
					'tags' => $this->loadCategoriesArray()
				));
				Render::addTemplate('edit', array(
					'path' => 'setting',
					'langs' => $langs,
					'tags' => $this->loadSettingsArray()
				));
				break;
			case 'config-module':
				//renders the hardcoded messages edit page
				Render::addTemplate('edit', array(
					'path' => 'config-module',
					'langs' => $langs,
					'tags' => $this->buildTranslationTable('config-module')
				));
				break;
			default:
				//renders main page with selection of what part to edit
				Render::addTemplate('_page');
		}
	}

	private function showListOfModules()
	{
		$table = array();

		$modules = Module::getAll();
		
		foreach ($modules as $module) {
			$msgs = $this->checkModuleTranslation($module);
			$table[] = array(
				'module' => $module->getIdentifier(),
				'depfail' => $module->hasMissingDependencies(),
				'status' => $msgs
			);
		}

		sort($table);
		Render::addTemplate('module-list', array(
			'table' => $table
		));
	}
	
	private function showModule()
	{
		// Heading
		Render::addTemplate('module-heading', array(
			'module' => $this->module->getIdentifier(),
			'moduleName' => $this->module->getDisplayName()
		));
		// Templates
		$this->showModuleTemplates();
		// Messages
		$this->showModuleMessages();
	}
	
	private function showModuleTemplates()
	{
		$templateTags = $this->loadUsedTemplateTags();
		$data = array('module' => $this->module->getIdentifier());
		$list = array();
		$data['tagcount'] = 0;
		foreach ($templateTags as $templates) {
			$list = array_merge($list, $templates);
			$data['tagcount']++;
		}
		foreach (Dictionary::getLanguages(true) as $lang) {
			list($missing, $unused) = $this->getModuleTemplateStatus($lang['cc'], $templateTags);
			$data['langs'][] = array(
				'cc' => $lang['cc'],
				'name' => $lang['name'],
				'missing' => $missing,
				'unused' => $unused
			);
		}
		$data['templates'] = array();
		foreach (array_unique($list) as $template) {
			$data['templates'][] = array('template' => $template);
		}
		Render::addTemplate('template-list', $data);
	}
	
	private function showModuleMessages()
	{
		$messageTags = $this->loadUsedMessageTags();
		$data = array('module' => $this->module->getIdentifier());
		$list = array();
		$data['messagecount'] = 0;
		foreach ($messageTags as $templates) {
			$list = array_merge($list, array_keys($templates['files']));
			$data['messagecount']++;
		}
		foreach (Dictionary::getLanguages(true) as $lang) {
			list($missing, $unused) = $this->getModuleTranslationStatus($lang['cc'], 'messages', $messageTags);
			$data['langs'][] = array(
				'cc' => $lang['cc'],
				'name' => $lang['name'],
				'missing' => $missing,
				'unused' => $unused
			);
		}
		$data['files'] = array();
		foreach (array_unique($list) as $template) {
			$data['files'][] = array('file' => $template);
		}
		Render::addTemplate('message-list', $data);
	}

	private function showTemplateEdit()
	{
		Render::addTemplate('edit', array(
			'destlang' => $this->destLang,
			'language' => Dictionary::getLanguageName($this->destLang),
			'tags'     => $this->loadTemplateEditArray(),
			'module'   => $this->module->getIdentifier(),
			'section'  => $this->section
		));
	}

	private function showMessagesEdit()
	{
		Render::addTemplate('edit', array(
			'destlang' => $this->destLang,
			'language' => Dictionary::getLanguageName($this->destLang),
			'tags'     => $this->loadMessagesEditArray(),
			'module'   => $this->module->getIdentifier(),
			'section'  => $this->section
		));
	}

	/**
	 * Get all tags used by templates of the given module.
	 * @param \Module $module module in question, false to use the one being edited
	 * @return array index is tag, value is array of templates using that tag
	 */
	private function loadUsedTemplateTags($module = false)
	{
		if ($module === false) {
			$module = $this->module;
		}
		$tags = array();
		$path = 'modules/' . $module->getIdentifier() . '/templates';
		if (is_dir($path)) {
			// Return an array with the module language tags
			foreach ($this->getAllFiles($path, '.html') as $name) {
				$relTemplateName = substr($name, strlen($path), -5);
				foreach ($this->getTagsFromTemplate($name) as $tag) {
					$tags[$tag][] = $relTemplateName;
				}
			}
		}
		return $tags;
	}

	/**
	 * Get all message tags of the given module.
	 * Returns array indexed by tag, value is
	 * array(
	 *	    'data' => rest of arguments to message
	 *     'files' => array(filename => occurencecount, ...)
	 * )
	 *
	 * @param \Module $module module in question, false to use the one being edited
	 * @return array see above
	 */
	private function loadUsedMessageTags($module = false)
	{
		if ($module === false) {
			$module = $this->module;
		}
		$tags = $this->loadTagsFromPhp('/Message\s*::\s*add\w+\s*\(\s*[\'"](?<module>[^\'"\.]*)\.(?<tag>[^\'"]*)[\'"]\s*(?<data>\)|\,.*)/i',
			$this->getAllFiles('modules', '.php'));
		// Filter out tags that don't refer to this module
		foreach (array_keys($tags) as $tag) {
			// Figure out if this is a message from this module or not
			if ($tags[$tag]['module'] === $module->getIdentifier()) {
				// Direct reference to this module via module.id
				continue;
			}
			unset($tags[$tag]);
		}
		$tags += $this->loadTagsFromPhp('/Message\s*::\s*add\w+\s*\(\s*[\'"](?<tag>[^\'"\.]*)[\'"]\s*(?<data>\)|\,.*)/i',
			$this->getModulePhpFiles($module));
		return $tags;
	}
	
	private function getTagsFromTemplate($templateFile)
	{
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
	 * Get missing and unused counters for given module's templates.
	 *
	 * @param type $lang lang to use
	 * @param type $tags
	 * @param type $module
	 * @return array list(missingCount, unusedCount)
	 */
	private function getModuleTemplateStatus($lang, $tags = false, $module = false)
	{
		if ($module === false) {
			$module = $this->module;
		}
		if ($tags === false) {
			$tags = $this->loadUsedTemplateTags();
		}
		$globalTranslation = Dictionary::getArray('main', 'global-template-tags', $lang);
		$translation = Dictionary::getArray($module->getIdentifier(), 'template-tags', $lang) + $globalTranslation;
		$matches = 0;
		$unused = 0;
		$expected = count($tags);
		foreach ($translation as $key => $value) {
			if(!isset($tags[$key])) {
				if (!in_array($key, $globalTranslation)) {
					$unused++;
				}
			} else {
				$matches++;
			}

		}
		$missing = $expected - $matches;
		return array($missing, $unused);
	}
	
	/**
	 * Get missing and unused counters for given module's templates.
	 *
	 * @param type $lang lang to use
	 * @param type $tags
	 * @param type $module
	 * @return array list(missingCount, unusedCount)
	 */
	private function getModuleTranslationStatus($lang, $file, $tags, $module = false)
	{
		if ($module === false) {
			$module = $this->module;
		}
		$translation = Dictionary::getArray($module->getIdentifier(), $file, $lang);
		$matches = 0;
		$unused = 0;
		$expected = count($tags);
		foreach ($translation as $key => $value) {
			if(!isset($tags[$key])) {
				$unused++;
			} else {
				$matches++;
			}

		}
		$missing = $expected - $matches;
		return array($missing, $unused);
	}

	private function checkModuleTranslation($module)
	{
		$tags = $this->loadUsedTemplateTags($module);
		$msgs = '';
		foreach (Dictionary::getLanguages() as $lang) {
			list($missing, $unused) = $this->getModuleTemplateStatus($lang, $tags, $module);

			$msg = "";
			if ($missing > 0) {
				$msg .= " [$missing JSON tag(s) are missing] ";
			}
			if ($unused > 0) {
				$msg .= " [$unused JSON tag(s) are not being used] ";
			}
			if(!empty($msg)) {
				$msgs .= "<div><div class='pull-left'><div class='badge'>$lang</div></div> $msg<div class='clearfix'></div></div>";
			}
		}
		if(empty($msgs)) {
			$msgs = 'OK';
		}
		return $msgs;
	}

	/**
	 * Finds and returns all PHP files of slxadmin.
	 *
	 * @return array of all php file names
	 */
	private function getAllFiles($dir, $extension)
	{
		$php = array();
		$extLen = -strlen($extension);
		foreach (scandir($dir, SCANDIR_SORT_NONE) as $name) {
			if ($name === '.' || $name === '..')
				continue;
			$name = $dir . '/' . $name;
			if (substr($name, $extLen) === $extension && is_file($name)) {
				$php[] = $name;
			} else if (is_dir($name)) {
				$php = array_merge($php, $this->getAllFiles($name, $extension));
			}
		}
		return $php;
	}

	/**
	 * Finds and returns all PHP files of current module.
	 *
	 * @param \Module $module Module to get the php files of
	 * @return array of php file names
	 */
	private function getModulePhpFiles($module)
	{
		return $this->getAllFiles('modules/' . $module->getIdentifier(), '.php');
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
		$json = Dictionary::getArray(substr($path, strlen("modules/")), $lang);
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
	 * Get array to pass to edit page with all the tags and translations.
	 *
	 * @param string $path the template's path
	 * @return array structure to pass to the tags list in the edit template
	 */
	private function loadTemplateEditArray()
	{
		$tags = $this->loadUsedTemplateTags();
		if ($tags === false)
			return false;
		$table = $this->buildTranslationTable('template-tags', array_keys($tags), true);
		$global = Dictionary::getArray($this->module->getIdentifier(), 'global-template-tags', $this->destLang);
		foreach ($table as &$entry) {
			if (empty($entry['translation']) && isset($global[$entry['tag']])) {
				$entry['placeholder'] = $global[$entry['tag']];
			}
			if (!isset($tags[$entry['tag']]))
				continue;
			$entry['notes'] = implode('<br>', $tags[$entry['tag']]);
		}
		return $table;
	}
	
	/**
	 * Get array to pass to edit page with all the message ids.
	 *
	 * @param string $path the template's path
	 * @return array structure to pass to the tags list in the edit template
	 */
	private function loadMessagesEditArray()
	{
		$tags = $this->loadUsedMessageTags();
		$table = $this->buildTranslationTable('messages', array_keys($tags), true);
		foreach ($table as &$entry) {
			if (!isset($tags[$entry['tag']]))
				continue;
			$tag =& $tags[$entry['tag']];
			// Add tag information
			if (isset($tag['data']) && is_string($tag['data'])) {
				$entry['notes'] = '<b>Params: ' . $this->countMessageParams($tag['data']) . '</b>';
			} else {
				$entry['notes'] = '';
			}
			foreach ($tag['files'] as $file => $count) {
				$entry['notes'] .= '<br>' . htmlspecialchars($file) . ': ' . $count . '×';
			}
		}
		return $table;
	}
	
	private function countMessageParams($str)
	{
		$quote = false;
		$escape = false;
		$count = 0;
		$len = strlen($str);
		$depth = 0;
		for ($i = 0; $i < $len; ++$i) {
			$char = $str{$i};
			if ($escape) {
				$escape = false;
				continue;
			}
			if ($quote === false) {
				if ($char === ',') {
					if ($depth === 0) {
						$count++;
					}
				} elseif ($char === '"' || $char === "'") {
					$quote = $char;
				} elseif ($char === '{' || $char === '(' || $char === '[') {
					$depth++;
				} elseif ($char === '}' || $char === ')' || $char === ']') {
					$depth--;
				}
			} else {
				if ($char === $quote) {
					$quote = false;
				} elseif ($char === '\\') {
					$escape = true;
				}
			}
		}
		return $count;
	}

	/**
	 * Load array of tags used in all the php files, by given regexp.
	 * The capture group containing the tag name must be named tag, which
	 * can be achieved by using (?<tag>..). All other capture groups will
	 * be returned in the resulting array.
	 * The return value is an array indexed by tag, and the value for each tag is of type
	 * array('captureX' => captureX, 'captureY' => captureY, 'files' => array(
	 *	    file1 => count,
	 *	    fileN => count
	 * )).
	 *
	 * @param string $regexp regular expression
	 * @param array $files list of files to scan
	 * @return array of all tags found, where the tag is the key, and the value is as described above
	 */
	private function loadTagsFromPhp($regexp, $files)
	{
		// Get all php files, so we can find all strings that need to be translated
		$tags = array();
		// Now find all tags in all php files. Only works for literal usage, not something like $foo = 'bar'; Dictionary::translate($foo);
		foreach ($files as $file) {
			$content = @file_get_contents($file);
			if ($content === false || preg_match_all($regexp, $content, $out, PREG_SET_ORDER) < 1)
				continue;
			foreach ($out as $set) {
				if (!isset($tags[$set['tag']])) {
					$tags[$set['tag']] = $set;
					$tags[$set['tag']]['files'] = array();
				}
				if (isset($tags[$set['tag']]['files'][$file])) {
					$tags[$set['tag']]['files'][$file]++;
				} else {
					$tags[$set['tag']]['files'][$file] = 1;
				}
			}
		}
		return $tags;
	}

	private function buildTranslationTable($file, $requiredTags = false, $findAlreadyTranslated = false)
	{
		$tags = array();
		if ($requiredTags !== false) {
			foreach ($requiredTags as $tagName) {
				$tags[$tagName] = array('tag' => $tagName, 'required' => true);
			}
		}
		// Sort here, so all tags known to be used are in alphabetical order
		ksort($tags);
		// Finds every tag within the JSON language file
		$jsonTags = Dictionary::getArray($this->module->getIdentifier(), $file, $this->destLang);
		if (is_array($jsonTags)) {
			// Sort these separately so unused tags will be at the bottom of the list, but still ordered alphabetically
			ksort($jsonTags);
			foreach ($jsonTags as $tag => $translation) {
				$tags[$tag]['translation'] = $translation;
				if (strpos($translation, "\n") !== false) {
					$tags[$tag]['big'] = true;
				}
				$tags[$tag]['tag'] = $tag;
			}
		}
		if ($findAlreadyTranslated) {
			$srcLangs = array_merge(array(LANG), array('en'), Dictionary::getLanguages());
			$srcLangs = array_unique($srcLangs);
			$key = array_search($this->destLang, $srcLangs);
			if ($key !== false) {
				unset($srcLangs[$key]);
			}
			foreach ($srcLangs as $lang) {
				$otherLang = Dictionary::getArray($this->module->getIdentifier(), $file, $lang);
				if (!is_array($otherLang))
					continue;
				$missing = false;
				foreach (array_keys($tags) as $tag) {
					if (isset($tags[$tag]['samplelang']))
						continue;
					if (!isset($otherLang[$tag])) {
						$missing = true;
					} else {
						$tags[$tag]['samplelang'] = $lang;
						$tags[$tag]['sampletext'] = $otherLang[$tag];
					}
				}
				if (!$missing)
					break;
			}
		}
		$tagid = 0;
		foreach ($tags as &$tag) {
			$tag['tagid'] = $tagid++;
			if ($requiredTags !== false) {
				// We have a list of required tags, so mark those that are missing or unused
				if (!isset($tag['required'])) {
					$tag['unused'] = true;
				} elseif (!isset($tag['translation'])) {
					$tag['missing'] = true;
				}
			}
		}
		// Finally remove $lang from the keys so mustache will iterate over them via {{#..}}
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
	
	private function getJsonFile()
	{
		$prefix = 'modules/' . $this->module->getIdentifier() . '/lang/' . $this->destLang;
		// File
		if ($this->section === 'messages') {
			return $prefix . '/messages.json';
		}
		if ($this->section === 'template') {
			return $prefix . '/template-tags.json';
		}
		// Custom submodule
		if ($this->section === 'custom') {
			if ($this->customHandler === false || !isset($this->customHandler['subsections'])) {
				Message::addError('no-custom-handlers');
				$this->redirect(1);
			}
			if (!in_array($this->subsection, $this->customHandler['subsections'], true)) {
				Message::addError('invalid-custom-handler', $this->subsection);
				$this->redirect(1);
			}
			return $prefix . '/' . $this->subsection;
		}
		Message::addError('invalid-section', $this->section);
		$this->redirect(1);
	}

	/**
	 * Updates a JSON file with it's new tags or/and tags values
	 */
	private function updateJson()
	{
		$this->ensureValidDestLanguage();
		if ($this->module === false) {
			Message::addError('main.no-module-given');
			$this->redirect();
		}
		$file = $this->getJsonFile();
		
		$data = array();

		//find the tag requests to change the file
		foreach ($_POST as $key => $value) {
			$str = explode('#!#', $key, 2);
			if (count($str) !== 2)
				continue;
			if ($str[0] === 'lang') {
				$tag = trim($str[1]);
				if (empty($tag)) {
					Message::addWarning('i18n-empty-tag');
					continue;
				}
				if (empty($value)) {
					unset($data[$tag]);
				} else {
					$data[$tag] = $value;
				}
			}
		}
		
		$translation = Request::post('new-text', array(), 'array');
		foreach (Request::post('new-id', array(), 'array') as $k => $tag) {
			if (empty($translation[$k]))
				continue;
			$data[(string)$tag] = (string)$translation[$k];
		}

		//saves the new values on the file
		$path = dirname($file);
		if (!is_dir($path)) {
			mkdir($path, 0775, true);
		}

		if (empty($data)) {
			if (file_exists($file)) {
				unlink($file);
			}
		} else {
			// JSON_PRETTY_PRINT is only available starting with php 5.4.0.... Use upgradephp's json_encode
			require_once('inc/up_json_encode.php');
			ksort($data); // Sort by key, so the diff on the output is cleaner
			$json = up_json_encode($data, JSON_PRETTY_PRINT); // Also for better diffability of the json files, we pretty print
			//exits the function in case the action was unsuccessful
			if (file_put_contents($file, $json) === false) {
				Message::addError('error-write', $file);
				return;
			}
		}

		Message::addSuccess('updated-tags');
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
