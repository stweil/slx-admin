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
		$this->listOrganizations();
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
		if (!User::hasPermission('superadmin'))
			return;

		$action = Request::post('action');
		if ($action === 'mail') {
			$this->handleTestMail();
		} elseif ($action === 'setmail' || $action === 'setsu' || $action == 'setlogin') {
			$this->setUserOption($action);
		} elseif ($action === 'setorglogin') {
			$this->setOrgOption($action);
		}
	}

	private function handleTestMail()
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
			$row['canlogin'] = $this->checked($row['canlogin']);
			$row['issuperuser'] = $this->checked($row['issuperuser']);
			$row['emailnotifications'] = $this->checked($row['emailnotifications']);
			$row['lastlogin'] = date('d.m.Y', $row['lastlogin']);
			$rows[] = $row;
		}
		Render::addTemplate('dozmod/userlist', array('users' => $rows));
	}

	private function listOrganizations()
	{
		$res = Database::simpleQuery('SELECT organizationid, displayname, canlogin FROM sat.organization'
				. ' ORDER BY displayname ASC');
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['canlogin'] = $this->checked($row['canlogin']);
			$rows[] = $row;
		}
		Render::addTemplate('dozmod/orglist', array('organizations' => $rows));
	}

	private function checked($val)
	{
		if ($val)
			return 'checked="checked"';
		return '';
	}

	private function setUserOption($option)
	{
		$val = (string) Request::post('value', '-');
		if ($val !== '1' && $val !== '0')
			die('Nein');
		if ($option === 'setmail') {
			$field = 'emailnotifications';
		} elseif ($option === 'setsu') {
			$field = 'issuperuser';
		} elseif ($option === 'setlogin') {
			$field = 'canlogin';
		} else {
			die('Unknown');
		}
		$user = (string)Request::post('userid', '?');
		$ret = Database::exec("UPDATE sat.user SET $field = :onoff WHERE userid = :userid", array(
				'userid' => $user,
				'onoff' => $val
		));
		error_log("Setting $field to $val for $user - affected: $ret");
		if ($ret === false)
			die('Error');
		if ($ret == 0)
			die(1 - $val);
		die($val);
	}

	private function setOrgOption($option)
	{
		$val = (string) Request::post('value', '-');
		if ($val !== '1' && $val !== '0')
			die('Nein');
		if ($option === 'setorglogin') {
			$field = 'canlogin';
		} else {
			die('Unknown');
		}
		$ret = Database::exec("UPDATE sat.organization SET $field = :onoff WHERE organizationid = :organizationid", array(
				'organizationid' => (string)Request::post('organizationid', ''),
				'onoff' => $val
		));
		if ($ret === false)
			die('Error');
		if ($ret === 0)
			die(1 - $val);
		die($val);
	}

}
