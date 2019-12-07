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
		$cidr = Request::post('cidr', Request::REQUIRED, 'string');
		$range = IpUtil::parseCidr($cidr);
		if ($range === false) {
			Message::addError('invalid-cidr', $cidr);
			return;
		}
		$ret = Database::exec('INSERT INTO reboot_subnet (start, end, fixed, isdirect)
				VALUES (:start, :end, 1, 0)', [
			'start' => $range['start'],
			'end' => $range['end'],
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
			if (empty($hosts)) {
				Database::exec('DELETE FROM reboot_jumphost_x_subnet WHERE subnetid = :id AND', ['id' => $id]);
			} else {
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
       		nextdirectcheck, lastseen, seencount, Count(hxs.hostid) AS jumphostcount, Count(sxs.srcid) AS sourcecount
				FROM reboot_subnet s
				LEFT JOIN reboot_jumphost_x_subnet hxs USING (subnetid)
				LEFT JOIN reboot_subnet_x_subnet sxs ON (s.subnetid = sxs.dstid AND sxs.reachable <> 0)
				GROUP BY subnetid, start, end
				ORDER BY start ASC, end DESC');
		$deadline = strtotime('-60 days');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['cidr'] = IpUtil::rangeToCidr($row['start'], $row['end']);
			$row['lastseen_s'] = Util::prettyTime($row['lastseen']);
			if ($row['lastseen'] && $row['lastseen'] < $deadline) {
				$row['lastseen_class'] = 'text-danger';
			}
			$nets[] = $row;
		}
		$data = ['subnets' => $nets];
		Render::addTemplate('subnet-list', $data);
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
		$subnet['cidr'] = IpUtil::rangeToCidr($subnet['start'], $subnet['end']);
		$subnet['start_s'] = long2ip($subnet['start']);
		$subnet['end_s'] = long2ip($subnet['end']);
		// Get list of jump hosts
		$res = Database::simpleQuery('SELECT h.hostid, h.host, h.port, hxs.subnetid FROM reboot_jumphost h
				LEFT JOIN reboot_jumphost_x_subnet hxs ON (h.hostid = hxs.hostid AND hxs.subnetid = :id)
				ORDER BY h.host ASC', ['id' => $id]);
		// Mark those assigned to the current subnet
		$jh = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['checked'] = $row['subnetid'] === null ? '' : 'checked';
			$jh[] = $row;
		}
		$subnet['jumpHosts'] = $jh;
		// Get list of all subnets that can broadcast into this one
		$res = Database::simpleQuery('SELECT s.start, s.end FROM reboot_subnet s
				INNER JOIN reboot_subnet_x_subnet sxs ON (s.subnetid = sxs.srcid AND sxs.dstid = :id AND sxs.reachable = 1)
				ORDER BY s.start ASC', ['id' => $id]);
		$sn = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$sn[] = ['cidr' => IpUtil::rangeToCidr($row['start'], $row['end'])];
		}
		$subnet['sourceNets'] = $sn;
		Permission::addGlobalTags($subnet['perms'], null, ['subnet.flag', 'jumphost.view', 'jumphost.assign-subnet']);
		Render::addTemplate('subnet-edit', $subnet);
	}

	public static function doAjax()
	{

	}

}
