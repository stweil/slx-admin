<?php

class Page_dozmod_users extends Page
{

	protected function doPreprocess()
	{

	}

	protected function doRender()
	{
		$this->listUsers();
		$this->listOrganizations();
	}

	protected function doAjax()
	{
		User::load();

		$action = Request::post('action', '', 'string');
		if ($action === 'setmail' || $action === 'setsu' || $action == 'setlogin') {
			if (User::hasPermission("users.".$action)) {
				$this->setUserOption($action);
			} else {
				die("No permission.");
			}

		} elseif ($action === 'setorglogin') {
			if (User::hasPermission("users.orglogin")) {
				$this->setOrgOption($action);
			} else {
				die("No permission.");
			}
		} else {
			die('No such action');
		}
	}

	// Helpers

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
		Render::addTemplate('userlist', array('users' => $rows));
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
		Render::addTemplate('orglist', array('organizations' => $rows));
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
		$user = (string) Request::post('userid', '?');
		$ret = Database::exec("UPDATE sat.user SET $field = :onoff WHERE userid = :userid", array(
			'userid' => $user,
			'onoff' => $val
		));
		error_log("Setting $field to $val for $user - affected: $ret");
		if ($ret === false)
			die('Error');
		if ($ret === 0)
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
			'organizationid' => (string) Request::post('organizationid', ''),
			'onoff' => $val
		));
		if ($ret === false)
			die('Error');
		if ($ret === 0)
			die(1 - $val);
		die($val);
	}

}