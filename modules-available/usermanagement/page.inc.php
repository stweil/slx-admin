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
				$this->create(Request::post('login'),Request::post('username'),Request::post('pass'),Request::post('phone'),Request::post('email'), 4);
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

		//$pag = new Paginate($users,$this->page);

		Render::addTemplate('user-management', array(
			'admin_id' => $admin[0],
			'admin_username' => $admin[1],
			'admin_name' => $admin[2],
			'admin_telephone' => $admin[3],
			'admin_email' => $admin[4]
			//'users' => $pag->getItems(),
			//'pages' => $pag->getPagination()
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

	private function create($login, $username, $password, $phone, $email){
		$data = array (
				'login' => $login,
				'pass' => Crypto::hash6 ( $password ),
				'name' => $username,
				'phone' => $phone,
				'email' => $email
		);
		User::addUser($data);
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
