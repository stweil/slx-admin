<?php

class Dictionary{
	private static $dictionary;
	private static $langArray;
	private static $messageArray;
	
	function build(){
		self::$dictionary = json_decode(file_get_contents("lang/dictionary.json"),true);
		foreach(self::$dictionary as $key => $text){
			self::$langArray[$key] = $text[LANG];
		}
		self::$messageArray = json_decode(file_get_contents("lang/".LANG."/messages.json"),true);
	}

	public static function getArray(){
		return self::$langArray;
	}

	public static function translate($string){
		return self::$langArray[$string];
	}

	public static function getMessages(){
		return self::$messageArray;
	}
	
}
