<?php

class Dictionary{
	private static $dictionary;
	private static $langArray;
	
	function build(){
		self::$dictionary = json_decode(file_get_contents("dictionary.json"),true);
		foreach(self::$dictionary as $key => $text){
			self::$langArray[$key] = $text[LANG];
		}
	}

	public static function getArray(){
		return self::$langArray;
	}
	
}
