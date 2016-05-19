<?php

class Page_Usermanagement extends Page
{
	private $page;
	private $deb;
	
	protected function doPreprocess()
	{
		User::load();

		$p = Request::get('page');
		if($p != false)
			$this->page = $p;
		else
			$this->page = 1;

		switch(Request::post('action')){
			case "editAdmin":
				$this->edit(Request::post('userid'),Request::post('username'),Request::post('phone'),Request::post('email'), 1);
				break;
			case "edit":
				$this->edit(Request::post('userid'),Request::post('username'),Request::post('phone'),Request::post('email'), 4);
				break;
			case "create":
				$this->create(Request::post('login'),Request::post('username'),Request::post('pass'),Request::post('phone'),Request::post('email'), Request::post('city'));
				break;
			case "delete":
				$this->delete(Request::post('userid'));
				break;
		}

		if(isset($_POST['userid']))		
			$this->deb = $_POST['userid'];

		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

	}

	protected function doRender()
	{
		// load every user
		$admin = array();
		$users = array();
		$res = Database::simpleQuery("SELECT userid, login, fullname, phone, email, permissions FROM user ORDER BY userid DESC");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['permissions'] == 1 )
				$admin = array($row['userid'],$row['login'],$row['fullname'],$row['phone'],$row['email']);
			else
				$users[] = array(
					'id' => $row['userid'],
					'username' => $row['login'],
					'name' => $row['fullname'],
					'telephone' => $row['phone'],
					'email' => $row['email']
				);
		}

		// load every city
		$cities = array();
		$res = Database::simpleQuery("SELECT cityid, name, ip FROM cities ORDER BY name DESC");
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$cities[] = array(
				'id' => $row['cityid'],
				'name' => $row['name'],
				'ip' => $row['ip']
			);
		}

		$pag = new Pagination($users,$this->page);

		Render::addTemplate('user-management', array(
			'admin_id' => $admin[0],
			'admin_username' => $admin[1],
			'admin_name' => $admin[2],
			'admin_telephone' => $admin[3],
			'admin_email' => $admin[4],
			'cities' => $cities,
			'users' => $pag->getItems(),
			'pages' => $pag->getPagination()
		));
	}

	private function edit($userid, $newname, $newphone, $newemail, $newpermissions){
		$data = array (
				'user' => $userid,
				'name' => $newname,
				'phone' => $newphone,
				'email' => $newemail,
				'permissions' => $newpermissions
		);
		Database::exec ( 'UPDATE user SET fullname = :name, phone = :phone, email = :email, permissions = :permissions WHERE userid = :user', $data );
		Message::addSuccess('update-user');
	}

	private function create($login, $username, $password, $phone, $email, $city){
		if (empty($login) || empty($username) || empty ($password)) {
			Message::addError ( 'empty-field' );
			Util::redirect ( '?do=Usermanagement' );
		} else {
			$data = array (
				'login' => $login,
				'pass' => Crypto::hash6 ( $password ),
				'name' => $username,
				'phone' => $phone,
				'email' => $email,
				'city' => $city,
				'permission' => 4
			);
			// TODO: Remove city column from user table; should be done in an n:m fashion via extra table
			Database::exec ( "INSERT INTO user SET login = :login, passwd = :pass, fullname = :name, phone = :phone, email = :email, city = :city, permissions = :permission", $data );
			$ret = Database::queryFirst('SELECT userid FROM user WHERE login = :user LIMIT 1', array('user' => $data['login']));
			$user = array(
				'user' => $ret['userid']
			);
			Database::exec ( "INSERT INTO setting_partition SET partition_id = '44', size = '5G', mount_point = '/tmp', user = :user", $user );
			Database::exec ( "INSERT INTO setting_partition SET partition_id = '43', size = '20G', mount_point = '/boot', options = 'bootable', user = :user", $user );
			Database::exec ( "INSERT INTO setting_partition SET partition_id = '40', size = '20G', mount_point = '/cache/export/dnbd3', user = :user", $user );
			Database::exec ( "INSERT INTO setting_partition SET partition_id = '41', size = '5G', mount_point = '/home', user = :user", $user );
			Database::exec ( "INSERT INTO setting_partition SET partition_id = '82', size = '1G', user = :user", $user );
			Message::addSuccess('add-user');
			EventLog::info ( User::getName () . ' created user ' . $data['login'] );
		}
	}

	private function delete($userid){
		$data = array (
				'userid' => $userid
		);
		Database::exec ( 'DELETE FROM setting_partition WHERE user = :userid', $data );
		Database::exec ( 'DELETE FROM setting_user WHERE user = :userid', $data );
		Database::exec ( 'DELETE FROM setting_values WHERE user = :userid', $data );
		Database::exec ( 'DELETE FROM user WHERE userid = :userid', $data );
		Message::addSuccess('delete-user');
	}

}
