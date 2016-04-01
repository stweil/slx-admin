<?php

class Page_Imgmanagement extends Page
{

	private $page;
	private $baselocation;
	private $images;

	protected function doPreprocess()
	{
		
		User::load();
		if (!User::hasPermission('baseconfig_local')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}


		//Depends on the server location;
		$this->baselocation = '/var/images/';
		$this->images = array();

		error_reporting(E_ALL);
		ini_set('display_errors','on');

		Session::get('token');

	}

	protected function doRender()
	{
		/*get city of user !!!!NOT TESTED!!!!

		$data=array( 'id'= User.getId());
		$res = Database::exec("SELECT cityid FROM user WHERE userid=:id",$data);
		$cityid = $res->fetch(PDO::FETCH_ASSOC);
		$res = Database::exec("SELECT name FROM cities WHERE cityid=:cityid",$cityid);
		$city = $res->fetch(PDO::FETCH_ASSOC);
		$location = $baselocation . $city;
		
	
		verify type of vars (string concatenation and more)
		!!!!NOT TESTED!!!!
		*/
		
		error_reporting(E_ALL);
		ini_set('display_errors','on');
		//Search images on location specified
		$location = $this->baselocation . 'curitiba/*';
		//Gets the configuration of each image
		$config = substr($location,0,-1).'config.json';
		$imgsactive = json_decode(file_get_contents($config),true);
		$images = glob($location, GLOB_ONLYDIR);
		$actives = array();
		$deactives= array();
		foreach($images as &$imgname){
			$imgname= substr($imgname, strlen($location)-1);
			//fill associative array (img->active[true/false])
			$this->images[$imgname] = isset($imgsactive[$imgname])?$imgsactive[$imgname] : false;
			if($this->images[$imgname]){
				array_push($actives, array('name' => $imgname));
			}else{
				array_push($deactives, array('name'=>$imgname));
			}
		}
	
		//Save eventually new images to config.json
		$fp = fopen($config,'w');
		fwrite($fp,json_encode($this->images));
		fclose($fp);
		Render::addTemplate('page-imgmanagement', array( 
			'deactives' => $deactives,
			'actives' => $actives));
	}
}
