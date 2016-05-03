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

		error_reporting(E_ALL);
		ini_set('display_errors','on');

		Session::get('token');

	}

	protected function doRender()
	{  
 		error_reporting(E_ALL);
		ini_set('display_errors','on');

        $actives = array();
        $deactives = array();

        $res = Database::simpleQuery("SELECT id, name, path, userid, is_template, is_active, description FROM images ORDER BY id DESC");
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            if($row['is_active'])
                $actives[] = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'path' => $row['path'],
                    'userid' => $row['userid'],
                    'is_template' => $row['is_template'],
                    'is_active' => $row['is_active'],
                    'description' => $row['description']
                );
            else
                $deactives[] = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'path' => $row['path'],
                    'userid' => $row['userid'],
                    'is_template' => $row['is_template'],
                    'is_active' => $row['is_active'],
                    'description' => $row['description']
                );
                
        }

		Render::addTemplate('page-imgmanagement', array( 
			'deactives' => $deactives,
			'actives' => $actives));
	}
}
