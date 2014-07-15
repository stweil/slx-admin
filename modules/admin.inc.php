<?php

class Page_Admin extends Page
{

	private $template = false;
	private $files = false;
	private $table = false;
	private $tags = false;
	
	/**
	 * Implementation of the abstract doPreprocess function
	 *
	 * Checks if the user is logged in and processes any
	 * action if one was specified in the request.
	 *
	 */
	protected function doPreprocess()
	{
		// load user, we will need it later
		User::load();
		
		// only admins should be able to access the administration page
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}
		
		if(Request::any('template')){
			$this->template = Request::any('template');
		}
		
	}

	/**
	 * Implementation of the abstract doRender function
	 *
	 * Fetch the list of news from the database and paginate it.
	 *
	 */
	protected function doRender()
	{
		if(!$this->template || !$this->templateAnalysis($this->template)){
			$this->initTable();
			Render::addTemplate('administration/_page', array(
				'token' => Session::get('token'),
				'adminMessage' => $this->message,
				'table' => $this->table
			));
		}else{
			Render::addTemplate('administration/template', array(
				'template' => $this->template,
				'tags' => $this->tags
			));
		}
		

	}
	
	private function initTable(){
		$this->listTemplates();
		$de = $this->listJson('de/');
		$en = $this->listJson('en/');
		$pt = $this->listJson('pt/');
		
		foreach($this->files as $key => $value){
			
			$this->table[] = array(
			'template' => $value,
			'link' => $key,
			'de' => $this->checkJson($de[$key],'de'),
			'en' => $this->checkJson($en[$key],'en'),
			'pt' => $this->checkJson($pt[$key],'pt')
			);
		}
		
	}
	
	private function listTemplates(){
		$this->files = array();
		$dir = 'templates/';
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach($objects as $name => $object){
			if(array_pop(explode('.',$name)) === 'html'){
				$key = str_replace($dir, '', $name);
				$key = str_replace('.html', '', $key);
		    		$this->files[$key] = $name;
		    	}
		}
	}
	
	private function listJson($lang){
		$json = array();
		$dir = 'lang/' . $lang;
		$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach($objects as $name => $object){
			if(array_pop(explode('.',$name)) === 'json'){
				$key = str_replace($dir, '', $name);
				$key = str_replace('.json', '', $key);
		    		$json[$key] = $key;
		    	}
		}
		return $json;
	}
	
	private function checkJson($path,$lang){
		if($path){
			$htmlTemplate = file_get_contents('templates/' . $path . '.html');
			$json = Dictionary::getArrayTemplate($path,$lang);
			$htmlCount = substr_count($htmlTemplate, 'lang_');
			$matchCount = 0;
			
			foreach($json as $key => $value){
				if($key != 'lang'){
					$key = $key . '}}';
					$matchCount += substr_count($htmlTemplate, $key);
				}
			}
			
			$diff = $htmlCount - $matchCount;
			
			//allright
			if($diff == 0) return "OK";
			if($diff > 0) return $diff . " JSON tag(s) are missing";
			if($diff < 0) return ($diff * -1) . " extra JSON tag(s)";
		}else{
			return "JSON file is missing";
		}
		
	}
	
	private function templateAnalysis($path){
		if(!file_exists('templates/' . $path . '.html')){
			Message::addError('invalid-template');
			return false;
		}
		$htmlTemplate = file_get_contents('templates/' . $path . '.html');
		preg_match_all('/{{lang_(.*?)}}/s', $htmlTemplate, $matches);
		
		$tags = $matches[1];
		
		foreach($tags as $tag){
			$this->tags[] = array(
				'tag' => 'lang_' . $tag,
				'de' => $this->checkJsonTag($path,$tag,'de/'),
				'en' => $this->checkJsonTag($path,$tag,'en/'),
				'pt' => $this->checkJsonTag($path,$tag,'pt/')
			);
		}
		
		return true;
	}
	
	private function checkJsonTag($path,$tag,$lang){
		if($json = Dictionary::getArrayTemplate($path,$lang)){
			return $json['lang_' . $tag];
		}
		return '';	
	}
}
