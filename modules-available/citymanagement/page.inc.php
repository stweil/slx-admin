<?php

class Page_Citymanagement extends Page
{

	private $page;
	
	protected function doPreprocess()
	{
		User::load();

		$p = Request::get('page');
		if($p != false)
			$this->page = $p;
		else
			$this->page = 1;
		switch(Request::post('action')){
			case "edit":
				$this->edit(Request::post('cityid'),Request::post('name'));
				break;
			case "create":
				$this->create(Request::post('name'));
				break;
			case "delete":
				$this->delete(Request::post('cityid'));
				break;
		}


		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

	}

	protected function doRender()
	{
		// load every city
		$cities = array();
		$res = Database::simpleQuery("SELECT cityid, name FROM cities ORDER BY cityid DESC");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$cities[] = array(
				'id' => $row['cityid'],
				'name' => $row['name'],
			);
		}

		$pag = new Pagination($cities,$this->page);

		Render::addTemplate('page-citymanagement', array(
			'cities' => $pag->getItems(),
			'pages' => $pag->getPagination()
		));
	}

	private function edit($cityid, $newname){
		$data = array (
				'cityid' => $cityid,
				'name' => $newname,
		);
		Database::exec ( 'UPDATE cities SET name = :name WHERE cityid = :cityid', $data );
		Message::addSuccess('update-city');
	}

	private function create($name){
		$data = array (
				'name' => $name,
		);
		Database::exec('INSERT INTO cities(name) VALUES( :name )',$data);
		Message::addSuccess('add-city');
	}

	private function delete($cityid){
		$data = array (
				'cityid' => $cityid
		);
		Database::exec ( 'DELETE FROM cities WHERE cityid = :cityid', $data );
		Message::addSuccess('delete-city');
	}
}
