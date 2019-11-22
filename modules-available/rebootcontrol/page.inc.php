<?php

class Page_RebootControl extends Page
{

	/**
	 * Called before any page rendering happens - early hook to check parameters etc.
	 */
	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main'); // does not return
		}

		$action = Request::post('action', 'show', 'string');

		if ($action === 'reboot' || $action === 'shutdown') {
			$this->execRebootShutdown($action);
		} elseif ($action === 'savejumphost') {
			$this->saveJumpHost();
		} elseif ($action === 'jumphost') {
			$this->postJumpHostDispatch();
		}
		if (Request::isPost()) {
			Util::redirect('?do=rebootcontrol');
		}
	}

	private function execRebootShutdown($action)
	{
		$requestedClients = Request::post('clients', false, 'array');
		if (!is_array($requestedClients) || empty($requestedClients)) {
			Message::addError('no-clients-selected');
			return;
		}

		$actualClients = RebootQueries::getMachinesByUuid($requestedClients);
		if (count($actualClients) !== count($requestedClients)) {
			// We could go ahead an see which ones were not found in DB but this should not happen anyways unless the
			// user manipulated the request
			Message::addWarning('some-machine-not-found');
		}
		// Filter ones with no permission
		foreach (array_keys($actualClients) as $idx) {
			if (!User::hasPermission('action.' . $action, $actualClients[$idx]['locationid'])) {
				Message::addWarning('locations.no-permission-location', $actualClients[$idx]['locationid']);
				unset($actualClients[$idx]);
			} else {
				$locationId = $actualClients[$idx]['locationid'];
			}
		}
		// See if anything is left
		if (!is_array($actualClients) || empty($actualClients)) {
			Message::addError('no-clients-selected');
			return;
		}
		usort($actualClients, function($a, $b) {
			$a = ($a['state'] === 'IDLE' || $a['state'] === 'OCCUPIED');
			$b = ($b['state'] === 'IDLE' || $b['state'] === 'OCCUPIED');
			if ($a === $b)
				return 0;
			return $a ? -1 : 1;
		});
		if ($action === 'shutdown') {
			$mode = 'SHUTDOWN';
			$minutes = Request::post('s-minutes', 0, 'int');
		} elseif (Request::any('quick', false, 'string') === 'on') {
			$mode = 'KEXEC_REBOOT';
			$minutes = Request::post('r-minutes', 0, 'int');
		} else {
			$mode = 'REBOOT';
			$minutes = Request::post('r-minutes', 0, 'int');
		}
		$task = RebootControl::execute($actualClients, $mode, $minutes, $locationId);
		if (Taskmanager::isTask($task)) {
			Util::redirect("?do=rebootcontrol&show=task&taskid=" . $task["id"]);
		}
		return;
	}

	private function saveJumpHost()
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
			$this->execCheckConnection($id);
		} else {
			Message::addError('no-such-jumphost', $id);
		}
	}

	private function postJumpHostDispatch()
	{
		$id = Request::post('checkid', false, 'int');
		if ($id !== false) {
			// Check connectivity
			$this->execCheckConnection($id);
			return;
		}
	}

	private function execCheckConnection($hostid)
	{
		$host = $this->getJumpHost($hostid);
		$script = str_replace(['%IP%', '%MACS%'], ['255.255.255.255', '00:11:22:33:44:55'], $host['script']);
		$task = RebootControl::runScript([[
			'clientip' => $host['host'],
			'port' => $host['port'],
			'username' => $host['username'],
		]], $script, 5, $host['sshkey']);
		if (!Taskmanager::isTask($task))
			return;
		TaskmanagerCallback::addCallback($task, 'rbcConnCheck', $hostid);
		Util::redirect('?do=rebootcontrol&show=task&type=checkhost&taskid=' . $task['id']);
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */

	protected function doRender()
	{
		$show = Request::get('show', 'jumphosts', 'string');

		// Always show public key (it's public, isn't it?)
		$data = ['pubkey' => SSHKey::getPublicKey()];
		Permission::addGlobalTags($data['perms'], null, ['newkeypair']);
		Render::addTemplate('header', $data);

		if ($show === 'task') {
			$this->showTask();
		} elseif ($show === 'jumphosts') {
			$this->showJumpHosts();
		} elseif ($show === 'jumphost') {
			$this->showJumpHost();
		}
	}

	private function showTask()
	{
		$taskid = Request::get("taskid", Request::REQUIRED, 'string');
		$task = Taskmanager::status($taskid);

		if (!Taskmanager::isTask($task) || !isset($task['data'])) {
			Message::addError('no-such-task', $taskid);
			return;
		}

		$td =& $task['data'];
		$type = Request::get('type', false, 'string');
		if ($type === false) {
			// Try to guess
			if (isset($td['locationId']) || isset($td['clients'])) {
				$type = 'reboot';
			} elseif (isset($td['result'])) {
				$type = 'exec';
			}
		}
		if ($type === 'reboot') {
			$data = [
				'taskId' => $task['id'],
				'locationId' => $td['locationId'],
				'locationName' => Location::getName($td['locationId']),
			];
			$uuids = array_map(function ($entry) {
				return $entry['machineuuid'];
			}, $td['clients']);
			$data['clients'] = RebootQueries::getMachinesByUuid($uuids);
			Render::addTemplate('status-reboot', $data);
		} elseif ($type === 'exec') {
			$data = [
				'taskId' => $task['id'],
			];
			Render::addTemplate('status-exec', $data);
		} elseif ($type === 'checkhost') {
			$ip = array_key_first($td['result']);
			$data = [
				'taskId' => $task['id'],
				'host' => $ip,
			];
			Render::addTemplate('status-checkconnection', $data);
		} else {
			Message::addError('unknown-task-type');
		}
	}

	private function showJumpHosts()
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

		// Append list of active reboot/shutdown tasks
		$allowedLocs = User::getAllowedLocations("action.*");
		$active = RebootControl::getActiveTasks($allowedLocs);
		if (!empty($active)) {
			foreach ($active as &$entry) {
				$entry['locationName'] = Location::getName($entry['locationId']);
			}
			unset($entry);
			Render::addTemplate('task-list', ['list' => $active]);
		}

	}

	private function showJumpHost()
	{
		User::assertPermission('jumphost.edit');
		$id = Request::get('id', Request::REQUIRED, 'string');
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
			$host = $this->getJumpHost($id);
		}
		Render::addTemplate('jumphost-edit', $host);
	}

	private function getJumpHost($hostid)
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

	protected function doAjax()
	{
		$action = Request::post('action', false, 'string');
		if ($action === 'generateNewKeypair') {
			User::assertPermission("newkeypair");
			Property::set("rebootcontrol-private-key", false);
			echo SSHKey::getPublicKey();
		} else {
			echo 'Invalid action.';
		}
	}

}

// Remove when we require >= 7.3.0
if (!function_exists('array_key_first')) {
	function array_key_first(array $arr) {
		foreach($arr as $key => $unused) {
			return $key;
		}
		return NULL;
	}
}
