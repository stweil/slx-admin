<?php

class Page_Admin extends Page
{

	private $template = false;
	private $path = false;
	private $page = false;
	private $update = false;
	private $files = false;
	private $table = false;
	private $delete = false;
	private $tags = false;
	private $unusedTags = false;
	private $message = false;

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
		if ($this->update)
			$this->updateJson();
		if ($this->delete && $this->template)
			$this->deleteTag($this->template, $this->delete);

		switch ($this->page) {
			case 'messages':
				Render::addTemplate('administration/messages', array(
					'token' => Session::get('token'),
					'msgs' => $this->initMsg(false),
					'msgsHC' => $this->initMsg(true)
				));
				break;
			case 'templates':
				if ($this->templateAnalysis($this->template)) {
					Render::addTemplate('administration/template', array(
						'token' => Session::get('token'),
						'template' => $this->template,
						'path' => $this->path,
						'tags' => $this->tags
					));
					break;
				}
			default:
				$this->initTable();
				Render::addTemplate('administration/_page', array(
					'token' => Session::get('token'),
					'adminMessage' => $this->message,
					'table' => $this->table
				));
		}
	}

	private function initTable()
	{
		$this->listTemplates();
		$de = $this->listJson('de/');
		$en = $this->listJson('en/');
		$pt = $this->listJson('pt/');

		foreach ($this->files as $key => $value) {

			$this->table[] = array(
				'template' => $value,
				'link' => $key,
				'de' => $this->checkJson($de[$key], 'de'),
				'en' => $this->checkJson($en[$key], 'en'),
				'pt' => $this->checkJson($pt[$key], 'pt')
			);
		}

		sort($this->table);
	}

	private function listTemplates()
	{
		$this->files = array();
		$dir = 'templates/';
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($objects as $name => $object) {
			if (array_pop(explode('.', $name)) === 'html') {
				$key = str_replace($dir, '', $name);
				$key = str_replace('.html', '', $key);
				$this->files[$key] = $name;
			}
		}
	}

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

	private function checkJson($path, $lang)
	{
		if (!$path) {
			return "JSON file is missing";
		} else {
			$htmlTemplate = file_get_contents('templates/' . $path . '.html');
			$json = Dictionary::getArrayTemplate($path, $lang);
			preg_match_all('/{{lang_(.*?)}}/s', $htmlTemplate, $matches);
			$htmlCount = count(array_unique($matches[1]));
			$matchCount = 0;

			foreach ($json as $key => $value) {
				if ($key != 'lang') {
					if (!in_array(preg_replace('/^lang_/', '', $key), $matches[1])) {
						$matchCount++;
					} else if ($value != '') {
						$matchCount++;
					}
				}
			}

			$diff = $htmlCount - $matchCount;
			if ($diff == 0)
				return "OK";
			if ($diff > 0)
				return $diff . " JSON tag(s) are missing";
			if ($diff < 0)
				return ($diff * -1) . " JSON tag(s) are not being used";
		}
	}

	private function templateAnalysis($path)
	{
		if (!file_exists('templates/' . $path . '.html')) {
			Message::addError('invalid-template');
			return false;
		}
		$htmlTemplate = file_get_contents('templates/' . $path . '.html');
		preg_match_all('/{{lang_(.*?)}}/s', $htmlTemplate, $matches);
		$tags = $matches[1];
		$tags = array_flip($tags);
		foreach ($tags as $key => $value) {
			$tags['lang_' . $key] = $value;
			unset($tags[$key]);
		}

		$langArray = array('en');
		$json = array();
		foreach ($langArray as $lang) {
			$jsonTags = Dictionary::getArrayTemplate($path, $lang);
			$json = array_merge($json, $jsonTags);
		}

		unset($json['lang']);
		$test = array_merge($json, $tags);

		foreach ($test as $tag => $value) {
			$this->tags[] = array(
				'tag' => $tag,
				'de' => $this->checkJsonTag($path, $tag, 'de/'),
				'en' => $this->checkJsonTag($path, $tag, 'en/'),
				'pt' => $this->checkJsonTag($path, $tag, 'pt/'),
				'class' => $this->checkJsonTags($path, $tag)
			);
		}

		$this->path = $path;

		return true;
	}

	private function checkJsonTag($path, $tag, $lang)
	{
		if ($json = Dictionary::getArrayTemplate($path, $lang)) {
			return $json[$tag];
		}
		return '';
	}

	private function checkJsonTags($path, $tag)
	{
		$htmlTemplate = file_get_contents('templates/' . $path . '.html');
		$htmlCount = substr_count($htmlTemplate, $tag);
		if ($htmlCount < 1)
			return "danger";

		$langArray = array('de/', 'en/', 'pt/');
		foreach ($langArray as $lang) {
			if ($json = Dictionary::getArrayTemplate($path, $lang)) {
				if (!isset($json[$tag]) || $json[$tag] == '')
					return 'warning';
			}
		}
		return '';
	}

	private function updateJson()
	{
		$langArray = Dictionary::getLanguages();
		$json = array();
		foreach ($langArray as $lang) {
			$json[$lang] = array();
		}

		foreach ($_REQUEST as $key => $value) {
			$str = explode('#', $key, 3);
			if (count($str) !== 3)
				continue;
			$pre = $str[0];
			$lang = $str[1];
			$tag = $str[2];
			if ($pre === 'lang') {
				if (in_array($lang, $langArray)) {
					if ($tag !== 'newtag')
						$json[$lang][$tag] = $value;
					else {
						$json[$lang][$_REQUEST['newtag']] = $value;
					}
				}
			}
		}

		foreach ($json as $key => $array) {
			$path = 'lang/' . $key . '/' . $_POST['path'] . '.json'; // TODO: Wtf? Unvalidated user input -> filesystem access!
			$json = json_encode($array, JSON_PRETTY_PRINT);
			if (@file_put_contents($path, $json) === false) {
				Message::addError('invalid-template');
				return false;
			}
		}
		Message::addSuccess('updated-tags');
	}

	private function initMsg($isHardcoded)
	{
		$msgs = array();
		$path = 'messages';
		if ($isHardcoded) {
			$path = 'messages-hardcoded';
		}
		$json = Dictionary::getArrayTemplate($path, $lang);
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

	private function deleteTag($path, $tag)
	{
		$langArray = unserialize(SITE_LANGUAGES);
		foreach ($langArray as $lang) {
			$json = Dictionary::getArrayTemplate($path, $lang);
			unset($json[$tag]);
			unset($json['lang']);
			//file_put_contents('test.txt','lang/' . $lang . '/' . $path . '.json');
			file_put_contents('lang/' . $lang . '/' . $path . '.json', json_encode($json)); // TODO: Check return code, show error if not writable
		}
		Message::addSuccess('deleted-tag');
	}

}
