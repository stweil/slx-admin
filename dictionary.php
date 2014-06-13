<?php

class Dictionary{
	private $dictionary;
	
	function __construct(){
		$this->dictionary = json_decode(file_get_contents("dictionary.json"),true);
	}

	
	public function translate($text){
		return $this->dictionary[$text][LANG];
	}
}
