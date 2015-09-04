<?php

class Page_DozMod extends Page
{

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('no-permission');
			Util::redirect('?do=Main');
		}

		$action = Request::post('action');

		if ($action === 'mail') {
			$this->mailHandler();
		} else if ($action === 'setuser') {
			$this->makeAdmin();
		}
	}

	protected function doRender()
	{
		// Mail config
		$conf = Database::queryFirst('SELECT value FROM sat.configuration WHERE parameter = :param', array('param' => 'mailconfig'));
		if ($conf != null) {
			$conf = @json_decode($conf['value'], true);
			if (is_array($conf)) {
				$conf['set_' . $conf['ssl']] = 'selected="selected"';
			}
		}
		Render::addTemplate('dozmod/mailconfig', $conf);
		// User list for making people admin
		$this->listUsers();
	}

	private function cleanMailArray()
	{
		$keys = array('host', 'port', 'ssl', 'senderAddress', 'replyTo', 'username', 'password', 'serverName');
		$data = array();
		foreach ($keys as $key) {
			$data[$key] = Request::post($key, '');
			settype($data[$key], 'string');
			if (is_numeric($data[$key])) {
				settype($data[$key], 'int');
			}
		}
		return $data;
	}

	protected function doAjax()
	{
		$do = Request::post('button');
		if ($do === 'test') {
			// Prepare array
			$data = $this->cleanMailArray();
			Header('Content-Type: text/plain; charset=utf-8');
			$data['recipient'] = Request::post('recipient', '');
			if (!preg_match('/.+@.+\..+/', $data['recipient'])) {
				$result = 'No recipient given!';
			} else {
				$result = Download::asStringPost('http://127.0.0.1:9080/do/mailtest', $data, 2, $code);
			}
			die($result);
		}
	}

	private function mailHandler()
	{
		// Check action
		$do = Request::post('button');
		if ($do === 'save') {
			// Prepare array
			$data = $this->cleanMailArray();
			$data = json_encode($data);
			Database::exec('INSERT INTO sat.configuration (parameter, value)'
				. ' VALUES (:param, :value)'
				. ' ON DUPLICATE KEY UPDATE value = VALUES(value)', array(
				'param' => 'mailconfig',
				'value' => $data
			));
			Message::addSuccess('mail-config-saved');
			Util::redirect('?do=DozMod');
		}
	}

	private function listUsers()
	{
		$res = Database::simpleQuery('SELECT userid, firstname, lastname, email, lastlogin, user.canlogin, issuperuser, emailnotifications,'
				. ' organization.displayname AS orgname FROM sat.user'
				. ' LEFT JOIN sat.organization USING (organizationid)'
				. ' ORDER BY lastname ASC, firstname ASC');
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['isbanned'] = $this->checked($row['canlogin'] == 0);
			$row['issuperuser'] = $this->checked($row['issuperuser']);
			$row['emailnotifications'] = $this->checked($row['emailnotifications']);
			$row['lastlogin'] = date('d.m.Y', $row['lastlogin']);
			$rows[] = $row;
		}
		Render::addTemplate('dozmod/userlist', array('users' => $rows));
	}
	
	private function checked($val) {
		if ($val)
			return 'checked="checked"';
		return '';
	}

	private function makeAdmin()
	{
		
	}

}
