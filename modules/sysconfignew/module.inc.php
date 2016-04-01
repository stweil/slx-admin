<?php

class Page_SysConfigNew extends Page
{
	// private $tmpath = '/srv/openslx/www/';
	private $tmpath = '/home/raul/tm-scripts/server';
	private $tmconfigs;
	private $tmmodules;

	protected function doPreprocess(){
		User::load();
		if (!User::hasPermission('baseconfig_local')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}

		$this->tmconfigs = $this->tmpath . '/configs';
		$this->tmmodules = $this->tmpath . '/modules';
	}


	protected function doRender(){
		$module = $_GET['module'];
		if(isset($module)){
			Render::addTemplate('module-editor',array(
				"module" => $module
			));
		}else{
			if(is_dir($this->tmpath)){
				$configs = array();
				$modules = array();

				foreach($this->listDirectory($this->tmconfigs) as $key => $value)
					$configs[] = array(
						"name" => $value
					);

				foreach($this->listDirectory($this->tmmodules) as $key => $value)
					$modules[] = array(
						"name" => $value
					);

				$data = array(
					"configs"  => $configs,
					"modules" => $modules
				);
				Render::addTemplate('_pagenew',$data);
			}else{
				Message::addError('no-tm-scripts');
			}
		}
	}

	protected function doAjax(){
		$request = $_GET['request'];
		switch($request){
			case "module-contents":
				$path = $this->tmpath . '/modules/' . Request::get('module');
				$data = $this->getDirContents($path);
				$json = json_encode($data);
				print_r($json);
				break;
			case "configs":
				$this->tmconfigs = $this->tmpath . '/configs';
				$this->tmmodules = $this->tmpath . '/modules';
				$userModules = $this->listDirectory($this->tmconfigs . '/' . Request::get('config'));
				$modules = array();
				foreach($this->listDirectory($this->tmmodules) as $key => $value){
					$chosen = (in_array($value, $userModules)) ? true : false;
					$modules[] = array(
						"name" => $value,
						"chosen" => $chosen
					);
				}

				foreach ($modules as $module) {
					$class = ($module['chosen']) ? "select-item select-item-selected" : "select-item";
					$ret .= "<button type='button' class='" . $class . "' onclick='select(this)' >";
					$ret .= $module['name'];
					$ret .= "</button>";
				}

				echo $ret;
				break;
		}

	}

	private function getDirContents($path){
		$ret = array();
		foreach ($this->listDirectory($path) as $key => $value) {
			if(is_dir($path . "/" . $value)){
				$ret["dir_" . $value] = $this->getDirContents($path . "/" . $value);
			}else{
				if(is_link($path . "/" . $value)){
					$ret["link_" . $value] = readlink($path . "/" . $value);
				}else{
					if(mime_content_type($path . "/" . $value) == "text/plain"){
						$ret["file_" . $value] = file_get_contents($path . "/" . $value);
					}else{
						$ret["lock_" . $value] = " oops";
					}
				}
			}
		}
		return $ret;
	}

	private function listDirectory($path){
		return array_diff(scandir($path), array('..', '.'));
	}

}
