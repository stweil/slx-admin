<?php

class SubPage
{

	public static function doPreprocess()
	{
		// Currently there's only one view, actions are ajax
		User::assertPermission('users.view');
	}

	public static function doRender()
	{
		self::listUsers();
		self::listOrganizations();
	}

	public static function doAjax()
	{
		User::load();

		$action = Request::post('action', '', 'string');
		if ($action === 'setmail' || $action === 'setsu' || $action == 'setlogin') {
			if (User::hasPermission("users.".$action)) {
				self::setUserOption($action);
			}
		} elseif ($action === 'setorglogin') {
			if (User::hasPermission("users.setorglogin")) {
				self::setOrgOption($action);
			}
		} else {
			die('No such action');
		}
	}

	// Helpers

	private static function listUsers()
	{
		$res = Database::simpleQuery('SELECT userid, firstname, lastname, email, lastlogin, user.canlogin, issuperuser, emailnotifications,'
			. ' organization.displayname AS orgname FROM sat.user'
			. ' LEFT JOIN sat.organization USING (organizationid)'
			. ' ORDER BY lastname ASC, firstname ASC');
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			settype($row['lastlogin'], 'int');
			$row['canlogin'] = self::checked($row['canlogin']);
			$row['issuperuser'] = self::checked($row['issuperuser']);
			$row['emailnotifications'] = self::checked($row['emailnotifications']);
			if ($row['lastlogin'] !== 0) {
				$row['lastlogin_s'] = date('d.m.Y', $row['lastlogin']);
			}
			$rows[] = $row;
		}
		Render::addTemplate('userlist', array(
			'users' => $rows,
			'nameTag' => User::hasPermission('actionlog.view') ? 'a' : 'span',
		));
	}

	private static function listOrganizations()
	{
		$res = Database::simpleQuery('SELECT organizationid, displayname, canlogin FROM sat.organization'
			. ' ORDER BY displayname ASC');
		$rows = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['canlogin'] = self::checked($row['canlogin']);
			$rows[] = $row;
		}
		Render::addTemplate('orglist', array('organizations' => $rows));
	}

	private static function checked($val)
	{
		if ($val)
			return 'checked="checked"';
		return '';
	}

	private static function setUserOption($option)
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

	private static function setOrgOption($option)
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
