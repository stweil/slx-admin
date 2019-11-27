<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', false, 'string');
		if ($action === 'add') {
			self::addSubnet();
		} elseif ($action === 'edit') {
			self::editSubnet();
		}
	}

	/*
	 * POST
	 */

	private static function addSubnet()
	{
		User::assertPermission('subnet.edit');
		$range = [];
		foreach (['start', 'end'] as $key) {
			$range[$key] = Request::post($key, Request::REQUIRED, 'string');
			$range[$key  . '_l'] = ip2long($range[$key]);
			if ($range[$key  . '_l'] === false) {
				Message::addError('invalid-ip-address', $range[$key]);
				return;
			}
		}
		if ($range['start_l'] > $range['end_l']) {
			Message::addError('invalid-range', $range['start'], $range['end']);
			return;
		}
		$ret = Database::exec('INSERT INTO reboot_subnet (start, end, fixed, isdirect)
				VALUES (:start, :end, 1, 0)', [
			'start' => sprintf('%u', $range['start_l']),
			'end' => sprintf('%u', $range['end_l']),
			], true);
		if ($ret === false) {
			Message::addError('subnet-already-exists');
		} else {
			Message::addSuccess('subnet-created');
			Util::redirect('?do=rebootcontrol&show=subnet&what=subnet&id=' . Database::lastInsertId());
		}
	}

	private static function editSubnet()
	{
		User::assertPermission('subnet.flag');
		$id = Request::post('id', Request::REQUIRED, 'int');
		$subnet = Database::queryFirst('SELECT subnetid
				FROM reboot_subnet WHERE subnetid = :id', ['id' => $id]);
		if ($subnet === false) {
			Message::addError('invalid-subnet', $id);
			return;
		}
		$params = [
			'id' => $id,
			'fixed' => !empty(Request::post('fixed', false, 'string')),
			'isdirect' => !empty(Request::post('isdirect', false, 'string')),
		];
		Database::exec('UPDATE reboot_subnet SET fixed = :fixed, isdirect = If(:fixed, :isdirect, isdirect)
				WHERE subnetid = :id', $params);
		if (User::hasPermission('jumphost.assign-subnet')) {
			$hosts = Request::post('jumphost', [], 'array');
			if (!empty($hosts)) {
				$hosts = array_keys($hosts);
				Database::exec('DELETE FROM reboot_jumphost_x_subnet WHERE subnetid = :id AND hostid NOT IN (:hosts)',
					['id' => $id, 'hosts' => $hosts]);
				$hosts = array_map(function($item) use ($id) {
					return [$item, $id];
				}, $hosts);
				Database::exec('INSERT IGNORE INTO reboot_jumphost_x_subnet (hostid, subnetid) VALUES :hosts', ['hosts' => $hosts]);
			}
		}
		Message::addSuccess('subnet-updated');
	}

	/*
	 * Render
	 */

	public static function doRender()
	{
		$what = Request::get('what', 'list', 'string');
		if ($what === 'list') {
			self::showSubnets();
		} elseif ($what === 'subnet') {
			self::showSubnet();
		}
	}

	private static function showSubnets()
	{
		User::assertPermission('subnet.*');
		$nets = [];
		$res = Database::simpleQuery('SELECT subnetid, start, end, fixed, isdirect,
       		lastdirectcheck, lastseen, seencount, Count(hxs.hostid) AS jumphostcount
				FROM reboot_subnet
				LEFT JOIN reboot_jumphost_x_subnet hxs USING (subnetid)
				GROUP BY subnetid, start, end
				ORDER BY start ASC, end DESC');
		$deadline = strtotime('-60 days');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['start_s'] = long2ip($row['start']);
			$row['end_s'] = long2ip($row['end']);
			$row['lastseen_s'] = Util::prettyTime($row['lastseen']);
			if ($row['lastseen'] && $row['lastseen'] < $deadline) {
				$row['lastseen_class'] = 'text-danger';
			}
			$nets[] = $row;
		}
		$data = ['subnets' => $nets];
		Render::addTemplate('subnet-list', $data);
		Module::isAvailable('js_ip');
	}

	private static function showSubnet()
	{
		User::assertPermission('subnet.*');
		$id = Request::get('id', Request::REQUIRED, 'int');
		$subnet = Database::queryFirst('SELECT subnetid, start, end, fixed, isdirect
				FROM reboot_subnet WHERE subnetid = :id', ['id' => $id]);
		if ($subnet === false) {
			Message::addError('invalid-subnet', $id);
			return;
		}
		$subnet['start_s'] = long2ip($subnet['start']);
		$subnet['end_s'] = long2ip($subnet['end']);
		$res = Database::simpleQuery('SELECT h.hostid, h.host, h.port, hxs.subnetid FROM reboot_jumphost h
				LEFT JOIN reboot_jumphost_x_subnet hxs ON (h.hostid = hxs.hostid AND hxs.subnetid = :id)
				ORDER BY h.host ASC', ['id' => $id]);
		$jh = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['checked'] = $row['subnetid'] === null ? '' : 'checked';
			$jh[] = $row;
		}
		$subnet['jumpHosts'] = $jh;
		Permission::addGlobalTags($subnet['perms'], null, ['subnet.flag', 'jumphost.view', 'jumphost.assign-subnet']);
		Render::addTemplate('subnet-edit', $subnet);
	}

	public static function doAjax()
	{

	}

}