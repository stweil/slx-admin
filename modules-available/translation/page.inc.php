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
	 * @var string Language being handled (if any in current step)
	 */
	private $destLang = false;
	
	/*
	 * 
	 */
	
	public function __construct()
	{
		$this->builtInSections = array('template', 'messages', 'module', 'menucategory', 'custom');
	}
	
	private function isValidSection($section)
	{
		return in_array($section, $this->builtInSections);
	}
	
	private function loadCustomHandler($moduleName)
	{
		$path = 'modules/' . $moduleName . '/hooks/translation.inc.php';
		$HANDLER = array();
		if (file_exists($path)) {
			require $path;
		}
		$backup = $HANDLER;
		foreach (glob('modules/*/hooks/translation-global.inc.php', GLOB_NOSORT) as $path) {
			$HANDLER = array();
			require $path;
			if (empty($HANDLER['subsections']))
				continue;
			foreach ($HANDLER['subsections'] as $sub) {
				$suf = '';
				while (isset($backup['subsections']) && in_array($sub . $suf, $backup['subsections'])) {
					$suf = mt_rand();
				}
				$backup['subsections'][] = $sub . $suf;
				if (isset($HANDLER['grep_' . $sub])) {
					$backup['grep_' . $sub . $suf] = $HANDLER['grep_' . $sub];
				}
			}
		}
		if (empty($backup))
			return false;
		return $backup;
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

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
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
		
		// Module
		if ($this->section === 'module') {
			$this->ensureValidDestLanguage();
			$this->showModuleEdit();
			return;
		}

		// Menu Category
		if ($this->section === 'menucategory') {
			$this->ensureValidDestLanguage();
			$this->showMenuCategoryEdit();
			return;
		}

		// Custom
		if ($this->section === 'custom') {
			$this->ensureValidDestLanguage();
			$this->showCustomEdit();
			return;
		}
		
		$this->redirect(1);
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
		Render::openTag('div', array('class' => 'row'));
		// Templates
		$this->showModuleTemplates();
		// Messages
		$this->showModuleMessages();
		// Other/hardcoded strings
		$this->showModuleStrings();
		// Menu categories
		$this->showModuleMenuCategories();
		// Module specific
		$this->showModuleCustom();
		Render::closeTag('div');
	}
	
	private function showModuleTemplates()
	{
		$templateTags = $this->loadUsedTemplateTags();
		$data = array('module' => $this->module->getIdentifier());
		$templateNames = array();
		$data['tagcount'] = 0;
		foreach ($templateTags as $templates) {
			$templateNames = array_merge($templateNames, $templates);
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
		foreach (array_unique($templateNames) as $template) {
			$data['templates'][] = array('template' => $template);
		}
		Render::addTemplate('template-list', $data);
	}
	
	private function showModuleMessages()
	{
		$messageTags = $this->loadUsedMessageTags();
		$data = array('module' => $this->module->getIdentifier());
		$phpFiles = array();
		$data['messagecount'] = 0;
		foreach ($messageTags as $templates) {
			$phpFiles = array_merge($phpFiles, array_keys($templates['files']));
			$data['messagecount']++;
		}
		foreach (Dictionary::getLanguages(true) as $lang) {
			list($missing, $unused) = $this->getModuleTranslationStatus($lang['cc'], 'messages', false, $messageTags);
			$data['langs'][] = array(
				'cc' => $lang['cc'],
				'name' => $lang['name'],
				'missing' => $missing,
				'unused' => $unused
			);
		}
		$data['files'] = array();
		foreach (array_unique($phpFiles) as $template) {
			$data['files'][] = array('file' => $template);
		}
		Render::addTemplate('message-list', $data);
	}
	
	private function showModuleStrings()
	{
		$moduleTags = $this->loadUsedModuleTags();
		$data = array('module' => $this->module->getIdentifier());
		$data['tagcount'] = count($moduleTags);
		foreach (Dictionary::getLanguages(true) as $lang) {
			list($missing, $unused) = $this->getModuleTranslationStatus($lang['cc'], 'module', true, $moduleTags);
			$data['langs'][] = array(
				'cc' => $lang['cc'],
				'name' => $lang['name'],
				'missing' => $missing,
				'unused' => $unused
			);
		}
		Render::addTemplate('string-list', $data);
	}

	private function showModuleMenuCategories()
	{
		$moduleTags = $this->loadUsedMenuCategories();
		$data = array('module' => $this->module->getIdentifier());
		$data['tagcount'] = count($moduleTags);
		foreach (Dictionary::getLanguages(true) as $lang) {
			list($missing, $unused) = $this->getModuleTranslationStatus($lang['cc'], 'menucategory', true, $moduleTags);
			$data['langs'][] = array(
				'cc' => $lang['cc'],
				'name' => $lang['name'],
				'missing' => $missing,
				'unused' => $unused
			);
		}
		Render::addTemplate('menu-category-list', $data);
	}
	
	private function showModuleCustom()
	{
		if ($this->customHandler === false)
			return;
		foreach ($this->customHandler['subsections'] as $subsection) {
			$this->showModuleCustomSubsection($subsection);
		}
	}
	
	private function showModuleCustomSubsection($subsection)
	{
		$moduleTags = $this->loadUsedCustomTags($subsection);
		$data = array(
			'subsection' => $subsection,
			'module' => $this->module->getIdentifier(),
			'tagcount' => $moduleTags === false ? '???' : count($moduleTags),
		);
		foreach (Dictionary::getLanguages(true) as $lang) {
			if ($moduleTags !== false) {
				list($missing, $unused) = $this->getModuleTranslationStatus($lang['cc'], $subsection, false, $moduleTags);
			} else {
				$missing = $unused = '???';
			}
			$data['langs'][] = array(
				'cc' => $lang['cc'],
				'name' => $lang['name'],
				'missing' => $missing,
				'unused' => $unused
			);
		}
		Render::addTemplate('custom-list', $data);
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

	private function showModuleEdit()
	{
		Render::addTemplate('edit', array(
			'destlang' => $this->destLang,
			'language' => Dictionary::getLanguageName($this->destLang),
			'tags'     => $this->loadModuleEditArray(),
			'module'   => $this->module->getIdentifier(),
			'section'  => $this->section
		));
	}

	private function showMenuCategoryEdit()
	{
		Render::addTemplate('edit', array(
			'destlang' => $this->destLang,
			'language' => Dictionary::getLanguageName($this->destLang),
			'tags'     => $this->loadMenuCategoryEditArray(),
			'module'   => $this->module->getIdentifier(),
			'section'  => $this->section
		));
	}

	private function showCustomEdit()
	{
		Render::addTemplate('edit', array(
			'destlang' => $this->destLang,
			'language' => Dictionary::getLanguageName($this->destLang),
			'tags'     => $this->loadCustomEditArray(),
			'module'   => $this->module->getIdentifier(),
			'section'  => $this->section,
			'subsection' => $this->subsection
		));
	}

	/**
	 * Get all tags used by templates of the given module.
	 * @param \Module $module module in question, false to use the one being edited.
	 *
	 * @return array of array(tag => array of templates using that tag)
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
		$allFiles = $this->getAllFiles('modules', '.php');
		if ($module->getIdentifier() === 'main') {
			$allFiles = array_merge($allFiles, $this->getAllFiles('apis', '.php'), $this->getAllFiles('inc', '.php'));
			$allFiles[] = 'index.php';
		}
		$full = $this->loadTagsFromPhp('/Message\s*::\s*add\w+\s*\(\s*[\'"](?<tag>[^\'"\.]*\.[^\'"]*)[\'"]\s*(?<data>\)|\,.*)/i',
			$allFiles);
		$tags = [];
		// Filter out tags that don't refer to this module
		foreach ($full as $tag) {
			$p = explode('.', $tag['tag'], 2);
			// Figure out if this is a message from this module or not
			if ($p[0] === $module->getIdentifier()) {
				// Direct reference to this module via module.id
				$tag['tag'] = $p[1];
				$tags[$p[1]] = $tag;
			}
		}
		$tags = $this->loadTagsFromPhp('/Message\s*::\s*add\w+\s*\(\s*[\'"](?<tag>[^\'"\.]*)[\'"]\s*(?<data>\)|\,.*)/i',
			$this->getModulePhpFiles($module), $tags);
		return $tags;
	}

	/**
	 * Get all module tags used/required.
	 *
	 * @param string $module
	 * @return array of array(tagname => (bool)required)
	 */
	private function loadUsedModuleTags($module = false)
	{
		if ($module === false) {
			$module = $this->module;
		}
		$tags = $this->loadTagsFromPhp('/Dictionary\s*::\s*translate\s*\(\s*[\'"](?<tag>[^\'"\.]*)[\'"]\s*[\),]/i',
			$this->getModulePhpFiles($module));
		foreach ($tags as &$tag) {
			$tag = true;
		}
		unset($tag);
		// Fixup special tags
		if ($module->getCategory() === false) {
			unset($tags['module_name']);
			unset($tags['page_title']);
		} else {
			$tags['module_name'] = true;
			$tags['page_title'] = false;
		}
		return $tags;
	}

	private function loadUsedMenuCategories($module = false)
	{
		if ($module === false) {
			$module = $this->module;
		}
		$skip = strlen($module->getIdentifier()) + 1;
		$match = $module->getIdentifier() . '.';
		$want = array();
		foreach (Module::getAll() as $module) {
			$cat = $module->getCategory();
			if (is_string($cat) && substr($cat, 0, $skip) === $match) {
				$want[substr($cat, $skip)] = true;
			}
		}
		return $want;
	}

	/**
	 * Return list of existing tags needing translation. This calls
	 * the function defined by the module. which is expected to return
	 * an array serving as a list, where the KEYS are the tags expected,
	 * the value of each entry can be false if the tag is optional.
	 *
	 * @param string $subsection Name of subsection
	 * @return array|false List of tags as KEYS of array
	 */
	private function loadUsedCustomTags($subsection)
	{
		if (!isset($this->customHandler['grep_'.$subsection]))
			return false;
		return $this->customHandler['grep_'.$subsection]($this->module);
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
	 * @param string $lang lang to use
	 * @param array $tags Array of tags, where the tag names are the keys
	 * @param \Module|false $module the module to work with, defaults to the currently edited module
	 * @return array [missingCount, unusedCount]
	 */
	private function getModuleTemplateStatus($lang, $tags = false, $module = false)
	{
		return $this->getModuleTranslationStatus($lang, 'template-tags', true, $tags, $module);
	}
	
	/**
	 * Get missing and unused counters for given translation unit.
	 * This is a more general version of the getModuleTemplateStatus function,
	 * which is special since it uses fallback to global translations.
	 *
	 * @param string $lang lang cc to use
	 * @param string $file the name of the translation file to load for checking
	 * @param boolean $fallback whether to check the global-tags of the main module as fallback
	 * @param array $tags list of tags that are expected to exist. Tags are the array keys!
	 * @param \Module|false $module the module to work with, defaults to the currently edited module
	 * @return array [missingCount, unusedCount]
	 */
	private function getModuleTranslationStatus($lang, $file, $fallback, $tags, $module = false)
	{
		if ($module === false) {
			$module = $this->module;
		}
		if ($fallback) {
			$globalTranslation = Dictionary::getArray('main', 'global-tags', $lang);
		} else {
			$globalTranslation = array();
		}
		$translation = Dictionary::getArray($module->getIdentifier(), $file, $lang) + $globalTranslation;
		$matches = 0;
		$unused = 0;
		$expected = 0;
		foreach ($tags as $v) {
			if ($v !== false) {
				$expected++;
			}
		}
		foreach (array_keys($translation) as $key) {
			if(!isset($tags[$key])) {
				if (!isset($globalTranslation[$key])) {
					$unused++;
				}
			} else {
				if ($tags[$key] !== false) {
					$matches++;
				}
			}

		}
		$missing = $expected - $matches;
		return array($missing, $unused);
	}

	private function checkModuleTranslation($module)
	{
		$templateTags = $this->loadUsedTemplateTags($module);
		$messageTags = $this->loadUsedMessageTags($module);
		$moduleTags = $this->loadUsedModuleTags($module);
		$msgs = '';
		foreach (Dictionary::getLanguages() as $lang) {
			list($m1, $u1) = $this->getModuleTemplateStatus($lang, $templateTags, $module);
			list($m2, $u2) = $this->getModuleTranslationStatus($lang, 'messages', true, $messageTags, $module);
			list($m3, $u3) = $this->getModuleTranslationStatus($lang, 'module', true, $moduleTags, $module);
			$missing = $m1 + $m2 + $m3;
			$unused = $u1 + $u2 + $u3;

			$msg = "";
			if ($missing > 0) {
				$msg .= " [$missing missing] ";
			}
			if ($unused > 0) {
				$msg .= " [$unused not being used] ";
			}
			if(!empty($msg)) {
				$msgs .= '<div><div class="pull-left">' . Dictionary::getFlagHtml(false, $lang) . '</div>' . $msg . '<div class="clearfix"></div></div>';
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
	 * Get array to pass to edit page with all the tags and translations.
	 *
	 * @param string $path the template's path
	 * @return array structure to pass to the tags list in the edit template
	 */
	private function loadTemplateEditArray()
	{
		$tags = $this->loadUsedTemplateTags();
		$table = $this->buildTranslationTable('template-tags', array_keys($tags), true);
		$global = Dictionary::getArray($this->module->getIdentifier(), 'global-tags', $this->destLang);
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
	
	/**
	 * Get array to pass to edit page with all the message ids.
	 *
	 * @param string $path the template's path
	 * @return array structure to pass to the tags list in the edit template
	 */
	private function loadModuleEditArray()
	{
		$tags = $this->loadUsedModuleTags();
		$table = $this->buildTranslationTable('module', array_keys($tags), true);
		return $table;
	}

	private function loadMenuCategoryEditArray()
	{
		$tags = $this->loadUsedMenuCategories();
		$table = $this->buildTranslationTable('categories', array_keys($tags), true);
		return $table;
	}
	
	/**
	 * Get array to pass to edit page with all the message ids.
	 *
	 * @param string $path the template's path
	 * @return array structure to pass to the tags list in the edit template
	 */
	private function loadCustomEditArray()
	{
		$tags = $this->loadUsedCustomTags($this->subsection);
		$table = $this->buildTranslationTable($this->subsection, array_keys($tags), true);
		return $table;
	}
	
	/**
	 * Quick and dirty method to count the parameters of a message/translate invocation.
	 * Expects the rest of an invocation, so e.g. addMessage('foo-foo', 'hi'); becomes
	 * , 'hi'); or addMessage('foo'); becomes just );
	 * This obviously fails if the call is spread over multiple lines.
	 *
	 * @param string $str the partial method call
	 * @return int number of arguments to the method, minus the message id
	 */
	private function countMessageParams($str)
	{
		$quote = false;
		$escape = false;
		$count = 0;
		$len = strlen($str);
		$depth = 0;
		for ($i = 0; $i < $len; ++$i) {
			$char = $str{$i};
			// Last char was backslash? Ignore this char
			if ($escape) {
				$escape = false;
				continue;
			}
			// We're inside quotes, watch for end or backslash
			if ($quote !== false) {
				if ($char === $quote) {
					$quote = false;
				} elseif ($char === '\\') {
					$escape = true;
				}
				continue;
			}
			// We're not inside quotes
			// Check if we have a parameter delimiter
			if ($char === ',') {
				// Check we're not in a nested method call
				if ($depth === 0) {
					$count++; // Increase parameter counter
				}
			} elseif ($char === '"' || $char === "'") {
				// Start of string
				$quote = $char;
			} elseif ($char === '{' || $char === '(' || $char === '[') {
				// Nested method etc.
				$depth++;
			} elseif ($char === '}' || $char === ')' || $char === ']') {
				// End nested method
				$depth--;
			}
		}
		// QnD special case for Message::add* using true as second param to add "go to" link.
		if (preg_match('/^\s*,\s*true\b/i', $str))
			return $count - 1;
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
	 * @param array $tags existing tag array to append to
	 * @return array of all tags found, where the tag is the key, and the value is as described above
	 */
	private function loadTagsFromPhp($regexp, $files, $tags = [])
	{
		// Get all php files, so we can find all strings that need to be translated
		// Now find all tags in all php files. Only works for literal usage, not something like $foo = 'bar'; Dictionary::translate($foo);
		foreach ($files as $file) {
			$content = file_get_contents($file);
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

	/**
	 * @param string $file Source dictionary
	 * @param string[]|false $requiredTags Tags that are considered required
	 * @param bool $findAlreadyTranslated If true, try to find a translation for this string in another language
	 * @return array numeric array suitable for passing to mustache
	 */
	private function buildTranslationTable($file, $requiredTags = false, $findAlreadyTranslated = false)
	{
		$tags = array();
		if (is_array($requiredTags)) {
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
			// For each tag, include a translated string from another language as reference
			$this->findTranslationSamples($file, $tags);
		}
		if ($file === 'template-tags' || $file === 'module') {
			$globals = Dictionary::getArray('main', 'global-tags', $this->destLang);
		} else {
			$globals = array();
		}
		$tagid = 0;
		foreach ($tags as &$tag) {
			$tag['tagid'] = $tagid++;
			if ($requiredTags !== false) {
				// We have a list of required tags, so mark those that are missing or unused
				if (!isset($tag['required'])) {
					$tag['unused'] = true;
				} elseif (!isset($tag['translation']) && !isset($globals[$tag['tag']])) {
					$tag['missing'] = true;
				}
				if (isset($globals[$tag['tag']])) {
					$tag['isglobal'] = true;
					$tag['placeholder'] = $globals[$tag['tag']];
				}
			}
		}
		// Finally remove tagname from the keys so mustache will iterate over them via {{#..}}
		return array_values($tags);
	}

	/**
	 * Finds translation samples for the given tags in the given file, looking in all
	 * languages except the one currently being translated to. Prefers the language the
	 * user selected, then english, then everything else.
	 *
	 * @param string $file translation unit
	 * @param array $tags list of tags, formatted as used in buildTranslationTable()
	 */
	private function findTranslationSamples($file, &$tags)
	{
		$srcLangs = array_unique(array_merge(array(LANG), array('en'), Dictionary::getLanguages()));
		if (($key = array_search($this->destLang, $srcLangs)) !== false) {
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
		if ($this->section === 'module') {
			return $prefix . '/module.json';
		}
		if ($this->section === 'menucategory') {
			return $prefix . '/categories.json';
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
			return $prefix . '/' . $this->subsection . '.json';
		}
		Message::addError('invalid-section', $this->section);
		$this->redirect(1);
		return false;
	}

	/**
	 * Updates a JSON file with it's new tags or/and tags values
	 */
	private function updateJson()
	{
		$this->ensureValidDestLanguage();
		if ($this->module === false) {
			Message::addError('no-module-given');
			$this->redirect();
		}
		$file = $this->getJsonFile();
		
		$data = array();

		//find the tag requests to change the file
		$tags = Request::post('langtag', array(), 'array');
		foreach ($tags as $tag => $value) {
			error_log($tag . '=' . $value);
			$tag = trim($tag);
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
		$translation = Request::post('new-text', array(), 'array');
		foreach (Request::post('new-id', array(), 'array') as $k => $tag) {
			if (empty($translation[$k]) || empty($tag))
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
			ksort($data); // Sort by key, so the diff on the output is cleaner
			$json = json_encode($data, JSON_PRETTY_PRINT); // Also for better diffability of the json files, we pretty print
			//exits the function in case the action was unsuccessful
			if (file_put_contents($file, $json) === false) {
				Message::addError('main.error-write', $file);
				return;
			}
		}

		Message::addSuccess('updated-tags');
	}

}
