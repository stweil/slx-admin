<?php

class Dictionary{
	private static $dictionary;
	private static $messageArray;
	
	function build(){
		self::$dictionary = self::sliceArray(json_decode(file_get_contents("lang/dictionary.json"),true));
		self::$messageArray = self::sliceArray(json_decode(file_get_contents("lang/translations/messages.json"),true));
	}
	
	public static function getArray(){
		return self::$dictionary;
	}

	public static function getArrayTemplate($template){
		return self::sliceArray(json_decode(file_get_contents("lang/translations/" . $template . ".json"),true));
	}

	public static function translate($string){
		return self::$dictionary[$string];
	}

	public static function getMessages(){
		return self::$messageArray;
	}

	private static function sliceArray($array){
		foreach($array  as $key => $text){
			$array[$key] = $text[LANG];
		}
		return $array;
	}
	
}
