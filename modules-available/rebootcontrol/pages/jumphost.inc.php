<?php

class SubPage
{

	public static function doPreprocess()
	{
		$action = Request::post('action', false, 'string');
		if ($action === 'save') {
			self::saveJumpHost();
		} elseif ($action === 'list') {
			self::listAction();
		}
	}

	/*
	 * POST
	 */

	private static function listAction()
	{
		$id = Request::post('checkid', false, 'int');
		if ($id !== false) {
			// Check connectivity
			self::execCheckConnection($id);
			return;
		}
	}

	private static function execCheckConnection($hostid)
	{
		$host = self::getJumpHost($hostid);
		$task = RebootControl::wakeViaJumpHost($host, '255.255.255.255', [['macaddr' => '00:11:22:33:44:55']]);
		if (!Taskmanager::isTask($task))
			return;
		Util::redirect('?do=rebootcontrol&show=task&type=checkhost&what=task&taskid=' . $task['id']);
	}

	private static function saveJumpHost()
	{
		User::assertPermission('jumphost.edit');
		$id = Request::post('hostid', Request::REQUIRED, 'string');
		$host = Request::post('host', Request::REQUIRED, 'string');
		$port = Request::post('port', Request::REQUIRED, 'int');
		if ($port < 1 || $port > 65535) {
			Message::addError('invalid-port', $port);
			return;
		}
		$username = Request::post('username', Request::REQUIRED, 'string');
		$sshkey = Request::post('sshkey', Request::REQUIRED, 'string');
		$script = preg_replace('/\r\n?/', "\n", Request::post('script', Request::REQUIRED, 'string'));
		if ($id === 'new') {
			$ret = Database::exec('INSERT INTO reboot_jumphost (host, port, username, sshkey, script, reachable)
				VALUE (:host, :port, :username, :sshkey, :script, 0)', compact('host', 'port', 'username', 'sshkey', 'script'));
			$id = Database::lastInsertId();
		} else {
			$ret = Database::exec('UPDATE reboot_jumphost SET
				host = :host, port = :port, username = :username, sshkey = :sshkey, script = :script, reachable = 0
				WHERE hostid = :id', compact('host', 'port', 'username', 'sshkey', 'script', 'id'));
			if ($ret === 0) {
				$ret = Database::queryFirst('SELECT hostid FROM reboot_jumphost WHERE hostid = :id', ['id' => $id]);
				if ($ret !== false) {
					$ret = 1;
				}
			}
		}
		if ($ret > 0) {
			Message::addSuccess('jumphost-saved', $id);
			self::execCheckConnection($id);
		} else {
			Message::addError('no-such-jumphost', $id);
		}
	}

	/*
	 * Render
	 */

	public static function doRender()
	{
		$id = Request::get('id', false, 'string');
		if ($id !== false) {
			self::showJumpHost($id);
		} else {
			self::showJumpHosts();
		}
	}

	private static function showJumpHosts()
	{
		User::assertPermission('jumphost.*');
		$hosts = [];
		$res = Database::simpleQuery('SELECT hostid, host, port, Count(jxs.subnetid) AS subnetCount, reachable
				FROM reboot_jumphost jh
				LEFT JOIN reboot_jumphost_x_subnet jxs USING (hostid)
				GROUP BY hostid
				ORDER BY hostid');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$hosts[] = $row;
		}
		$data = [
			'jumpHosts' => $hosts
		];
		Permission::addGlobalTags($data['perms'], null, ['jumphost.edit', 'jumphost.assign-subnet']);
		Render::addTemplate('jumphost-list', $data);
	}

	private static function showJumpHost($id)
	{
		User::assertPermission('jumphost.edit');
		if ($id === 'new') {
			$host = ['hostid' => 'new', 'port' => 22, 'script' => "# Assume bash\n"
				. "MACS='%MACS%'\n"
				. "IP='%IP'\n"
				. "EW=false\n"
				. "WOL=false\n"
				. "command -v etherwake > /dev/null && ( [ \"\$(id -u)\" = 0 ] || [ -u \"\$(which etherwake)\" ] ) && EW=true\n"
				. "command -v wakeonlan > /dev/null && WOL=true\n"
				. "if \$EW && ( ! \$WOL || [ \"\$IP\" = '255.255.255.255' ] ); then\n"
				. "\tifaces=\"\$(ls -1 /sys/class/net/)\"\n"
				. "\t[ -z \"\$ifaces\" ] && ifaces=eth0\n"
				. "\tfor ifc in \$ifaces; do\n"
				. "\t\t[ \"\$ifc\" = 'lo' ] && continue\n"
				. "\t\tfor mac in \$MACS; do\n"
				. "\t\t\tetherwake -i \"\$ifc\" \"\$mac\"\n"
				. "\t\tdone\n"
				. "\tdone\n"
				. "elif \$WOL; then\n"
				. "\twakeonlan -i \"\$IP\" \$MACS\n"
				. "else\n"
				. "\techo 'No suitable WOL tool found' >&2\n"
				. "\texit 1\n"
				. "fi\n"];
		} else {
			$host = self::getJumpHost($id);
		}
		Render::addTemplate('jumphost-edit', $host);
	}

	public static function doAjax()
	{

	}

	/*
	 * MISC
	 */

	private static function getJumpHost($hostid)
	{
		$host = Database::queryFirst('SELECT hostid, host, port, username, sshkey, script
				FROM reboot_jumphost
				WHERE hostid = :id', ['id' => $hostid]);
		if ($host === false) {
			Message::addError('no-such-jumphost', $hostid);
			Util::redirect('?do=rebootcontrol');
		}
		return $host;
	}

}