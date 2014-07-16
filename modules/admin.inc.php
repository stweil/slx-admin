<?php

class Page_Admin extends Page
{

	private $template = false;
	private $path = false;
	private $page = false;
	private $update = false;
	private $files = false;
	private $table = false;
	private $tags = false;
	private $unusedTags = false;
	
	protected function doPreprocess()
	{
		User::load();
		
		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}
		
		if(Request::get('template')){
			$this->template = Request::get('template');
		}
		
		if(Request::get('page')){
			$this->page = Request::get('page');
		}
		
		if(Request::post('update')){
			$this->update = Request::post('update');
		}
		
	}

	protected function doRender()
	{
		if($this->update)	$this->updateJson();
		
		switch($this->page){
		case 'messages':
			Render::addTemplate('administration/messages', array(
					'token' => Session::get('token')
				));
			break;
		case 'templates':
			if($this->templateAnalysis($this->template)){
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
		
		sort($this->table);
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
		if(!$path){
			return "JSON file is missing";
		}else{
			$htmlTemplate = file_get_contents('templates/' . $path . '.html');
			$json = Dictionary::getArrayTemplate($path,$lang);
			$htmlCount = substr_count($htmlTemplate, 'lang_');
			$matchCount = 0;
			
			foreach($json as $key => $value){
				if($key != 'lang' && $value != ''){
					$key = $key . '}}';
					$matchCount += substr_count($htmlTemplate, $key);
					if(substr_count($htmlTemplate, $key) == 0) $matchCount++;
				}
			}
			
			$diff = $htmlCount - $matchCount;
		
			//allright
			if($diff == 0) return "OK";
			if($diff > 0) return $diff . " JSON tag(s) are missing";
			if($diff < 0) return ($diff * -1) . " JSON tag(s) are not being used";
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
		
		$this->path = $path;
		
		return true;
	}
	
	private function checkJsonTag($path,$tag,$lang){
		if($json = Dictionary::getArrayTemplate($path,$lang)){
			return $json['lang_' . $tag];
		}
		return '';	
	}
	
	private function updateJson(){
		$langArray = unserialize(SITE_LANGUAGES);
		$json = array(
			'de' => array(),
			'en' => array(),
			'pt' => array()
		);
		
		foreach($_REQUEST as $key => $value){
			$str = explode('-',$key);
			$pre = $str[0];
			$lang = $str[1];
			$tag = $str[2];
			if($pre == 'lang'){
				if(in_array($lang,$langArray)){
					$json[$lang][$tag] = $value;
				}
			}
			
		}
		
		foreach($json as $key => $array){
			$path = 'lang/' . $key . '/' . $_POST['path'] . '.json';
			$json = json_encode($array,true);
			if(!file_put_contents($path,$json))
				$this->message = "fail";
		}
	}
	
}
