<?php

class Dictionary{
	private static $messageArray;
	
	function build(){
		self::$messageArray = json_decode(file_get_contents("lang/" . LANG . "/messages.json"),true);
	}	

	public static function getArrayTemplate($template,$lang = false){
		$language = array('lang'=>LANG);
		if(!$lang)
			return array_merge($language,json_decode(file_get_contents("lang/" . LANG . "/" . $template . ".json"),true));
		return array_merge($language,json_decode(file_get_contents("lang/" . $lang . "/" . $template . ".json"),true));
	}

	public static function translate($string){
		$hardcoded = json_decode(file_get_contents("lang/" . LANG . "/messages-hardcoded.json"),true);
		return $hardcoded[$string];
	}

	public static function getMessages(){
		return self::$messageArray;
	}
	
}
	//Array containing the allowed languages for the website
	$langArray = unserialize(SITE_LANGUAGES);
	
	
	//Changes the language in case there is a request to
	if(isset($_GET['lang']))
	if(in_array($_GET['lang'],$langArray)){
		setcookie('lang',$_GET['lang'],time()+60*60*24*30*12);
		header('Location: ' . $_SERVER['HTTP_REFERER']);
	}
	
	//Default language
	$language = 'en';

	//Language from the browser
	$langBrowser = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);

	//User language	
	if(isset($_COOKIE['lang']) && in_array($_COOKIE['lang'],$langArray)){
		$language = $_COOKIE['lang'];
	}else if(in_array($langBrowser,$langArray)){
		$language = $langBrowser;
	}
	
	define('LANG', $language);
?>
