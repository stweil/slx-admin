<?php
	
	$langArray = array("english","german","portuguese");
	if(isset($_GET['lang']))
		if(in_array($_GET['lang'],$langArray))
			file_put_contents('lang.txt', $_GET['lang']);
	header('Location: ' . $_SERVER['HTTP_REFERER']);
?>
