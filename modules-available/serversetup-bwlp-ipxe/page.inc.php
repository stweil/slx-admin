<?php

class Page_ServerSetup extends Page
{

	private $addrListTask;
	private $compileTask = null;
	private $currentAddress;
	private $currentMenu;
	private $hasIpSet = false;

	private function getCompileTask()
	{
		if ($this->compileTask !== null)
			return $this->compileTask;
		$this->compileTask = Property::get('ipxe-task-id');
		if ($this->compileTask !== false) {
			$this->compileTask = Taskmanager::status($this->compileTask);
			if (!Taskmanager::isTask($this->compileTask) || Taskmanager::isFinished($this->compileTask)) {
				$this->compileTask = false;
			}
		}
		return $this->compileTask;
	}

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		if (Request::any('action') === 'getimage') {
			User::assertPermission("download");
			$this->handleGetImage();
		}

		$this->currentMenu = Property::getBootMenu();

		$action = Request::post('action');

		if ($action === false) {
			$this->currentAddress = Property::getServerIp();
			$this->getLocalAddresses();
		}

		if ($action === 'compile') {
			User::assertPermission("edit.address");
			if ($this->getCompileTask() === false) {
				Trigger::ipxe();
			}
			Util::redirect('?do=serversetup');
		}

		if ($action === 'ip') {
			User::assertPermission("edit.address");
			// New address is to be set
			$this->getLocalAddresses();
			$this->updateLocalAddress();
		}

		if ($action === 'savebootentry') {
			User::assertPermission('ipxe.bootentry.edit');
			$this->saveBootEntry();
		}

		if ($action === 'deleteBootentry') {
			User::assertPermission('ipxe.bootentry.edit');
			$this->deleteBootEntry();
		}

		if ($action === 'savemenu') {
			User::assertPermission('ipxe.menu.edit');
			$this->saveMenu();
		}

		if ($action === 'savelocation') {
			// Permcheck in function
			$this->saveLocationMenu();
			Util::redirect('?do=locations');
		}

		if ($action === 'savelocalboot') {
			User::assertPermission('ipxe.localboot.edit');
			$this->saveLocalboot();
		}

		if ($action === 'import') {
			User::assertPermission('ipxe.bootentry.edit');
			$this->execImportPxeMenu();
		}

		if ($action === 'deleteMenu') {
			// Permcheck in function
			$this->deleteMenu();
		}

		if ($action === 'setDefaultMenu') {
			User::assertPermission('ipxe.menu.edit', 0);
			$this->setDefaultMenu();
		}

		if (Request::isPost()) {
			Util::redirect('?do=serversetup');
		}

		User::assertPermission('access-page');

		$addr = false;
		if (User::hasPermission('ipxe.menu.view')) {
			Dashboard::addSubmenu('?do=serversetup&show=menu', Dictionary::translate('submenu_menu', true));
		}
		if (User::hasPermission('ipxe.bootentry.view')) {
			Dashboard::addSubmenu('?do=serversetup&show=bootentry', Dictionary::translate('submenu_bootentry', true));
		}
		if (User::hasPermission('edit.address')) {
			Dashboard::addSubmenu('?do=serversetup&show=address', Dictionary::translate('submenu_address', true));
			$addr = true;
		}
		if (User::hasPermission('download')) {
			Dashboard::addSubmenu('?do=serversetup&show=download', Dictionary::translate('submenu_download', true));
		}
		if (User::hasPermission('ipxe.localboot.*')) {
			Dashboard::addSubmenu('?do=serversetup&show=localboot', Dictionary::translate('submenu_localboot', true));
		}
		if (User::hasPermission('ipxe.bootentry.edit')) {
			Dashboard::addSubmenu('?do=serversetup&show=import', Dictionary::translate('submenu_import', true));
		}
		if (Request::get('show') === false) {
			$subs = Dashboard::getSubmenus();
			if (empty($subs)) {
				User::assertPermission('download');
			} elseif (Property::getServerIp() === 'invalid' && $addr) {
				Util::redirect('?do=serversetup&show=address');
			} else {
				Util::redirect($subs[0]['url']);
			}
		}
	}

	protected function doRender()
	{
		Render::addTemplate("heading");

		$show = Request::get('show');

		if (in_array($show, ['menu', 'address', 'download'])) {
			$task = $this->getCompileTask();
			if ($task !== false) {
				$files = [];
				if ($task['data'] && $task['data']['files']) {
					foreach ($task['data']['files'] as $k => $v) {
						$files[] = ['name' => $k, 'namehyphen' => str_replace(['/', '.'], '-', $k)];
					}
				}
				Render::addTemplate('ipxe_update', array('taskid' => $task['id'], 'files' => $files));
			}
		}

		switch ($show) {
		case 'editbootentry':
			User::assertPermission('ipxe.bootentry.*');
			$this->showEditBootEntry();
			break;
		case 'editmenu':
			User::assertPermission('ipxe.menu.view');
			$this->showEditMenu();
			break;
		case 'download':
			User::assertPermission('download');
			$this->showDownload();
			break;
		case 'menu':
			User::assertPermission('ipxe.menu.view');
			$this->showMenuList();
			break;
		case 'bootentry':
			User::assertPermission('ipxe.bootentry.view');
			$this->showBootentryList();
			break;
		case 'address':
			User::assertPermission('edit.address');
			$this->showEditAddress();
			break;
		case 'assignlocation':
			// Permcheck in function
			$this->showEditLocation();
			break;
		case 'localboot':
			User::assertPermission('ipxe.localboot.*');
			$this->showLocalbootConfig();
			break;
		case 'import':
			User::assertPermission('ipxe.bootentry.edit');
			$this->showImportMenu();
			break;
		default:
			Util::redirect('?do=serversetup');
			break;
		}
	}

	private function showDownload()
	{
		$list = glob('/srv/openslx/www/boot/download/*', GLOB_NOSORT);
		usort($list, function ($a, $b) {
			return strcmp(substr($a, -4), substr($b, -4)) * 100 + strcmp($a, $b);
		});
		$files = [];
		$strings = [
			'efi' => [Dictionary::translate('dl-efi', true) => 50],
			'pcbios' => [Dictionary::translate('dl-pcbios', true) => 51],
			'usb' => [Dictionary::translate('dl-usb', true) => 80],
			'hd' => [Dictionary::translate('dl-hd', true) => 81],
			'lkrn' => [Dictionary::translate('dl-lkrn', true) => 82],
			'i386' => [Dictionary::translate('dl-i386', true) => 10],
			'x86_64' => [Dictionary::translate('dl-x86_64', true) => 11],
			'ecm' => [Dictionary::translate('dl-usbnic', true) => 60],
			'ncm' => [Dictionary::translate('dl-usbnic', true) => 61],
			'ipxe' => [Dictionary::translate('dl-pcinic', true) => 62],
			'snp' => [Dictionary::translate('dl-snp', true) => 63],
		];
		foreach ($list as $file) {
			if ($file{0} === '.')
				continue;
			if (is_file($file)) {
				$base = basename($file);
				$features = [];
				foreach (preg_split('/[\-\.\/]+/', $base, -1, PREG_SPLIT_NO_EMPTY) as $p) {
					if (array_key_exists($p, $strings)) {
						$features += $strings[$p];
					}
				}
				asort($features);
				$files[] = [
					'name' => $base,
					'size' => Util::readableFileSize(filesize($file)),
					'modified' => Util::prettyTime(filemtime($file)),
					'class' => substr($base, -4) === '.usb' ? 'slx-bold' : '',
					'features' => implode(', ', array_keys($features)),
				];
			}
		}
		Render::addTemplate('download', ['files' => $files]);
	}

	private function makeSelectArray($list, $defaults)
	{
		$ret = ['EFI' => [], 'PCBIOS' => []];
		foreach (['PCBIOS', 'EFI'] as $m) {
			foreach (array_keys($list[$m]) as $k) {
				$ret[$m][] = [
					'key' => $k,
					'selected' => ($k === $defaults[$m] ? 'selected' : ''),
				];
			}
		}
		return $ret;
	}

	private function showLocalbootConfig()
	{
		// Default setting
		$default = Localboot::getDefault();
		$optionList = $this->makeSelectArray(Localboot::BOOT_METHODS, $default);
		// Exceptions
		$cutoff = strtotime('-90 days');
		$models = [];
		$res = Database::simpleQuery('SELECT m.systemmodel, cnt, sl.pcbios AS PCBIOS, sl.efi AS EFI FROM (
				SELECT m2.systemmodel, Count(*) AS cnt FROM machine m2
				WHERE m2.lastseen > :cutoff
				GROUP BY systemmodel
			) m
			LEFT JOIN serversetup_localboot sl USING (systemmodel)
			ORDER BY systemmodel', ['cutoff' => $cutoff]);
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['modelesc'] = urlencode($row['systemmodel']);
			$row['options'] = $this->makeSelectArray(Localboot::BOOT_METHODS, $row);
			$models[] = $row;
		}
		// Output
		$data = [
			'default' => $default,
			'options' => $optionList,
			'exceptions' => $models,
		];
		Render::addTemplate('localboot', $data);
	}

	private function showImportMenu()
	{
		Render::addTemplate('page-import');
	}

	private function showBootentryList()
	{
		$allowEdit = User::hasPermission('ipxe.bootentry.edit');

		$res = Database::simpleQuery("SELECT be.entryid, be.hotkey, be.title, be.builtin, Count(sm.menuid) AS refs FROM serversetup_bootentry be
				LEFT JOIN serversetup_menuentry sm USING (entryid)
				GROUP BY be.entryid
				ORDER BY be.title ASC");
		$bootentryTable = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$bootentryTable[] = $row;
		}

		if (empty($bootentryTable)) {
			if (Property::getServerIp() === false || Property::getServerIp() === 'invalid') {
				Message::addError('no-ip-set');
				Util::redirect('?do=serversetup&show=address');
			}
			IPxe::importLegacyMenu(true);
			$num = IPxe::importSubnetPxeMenus('/srv/openslx/tftp/pxelinux.cfg');
			if ($num > 0) {
				EventLog::info('Imported old PXELinux menu, with ' . $num . ' additional IP-range based menus.');
			} else {
				EventLog::info('Imported old PXELinux menu.');
			}
			Util::redirect('?do=serversetup&show=bootentry');
		}

		Render::addTemplate('bootentry-list', array(
			'bootentryTable' => $bootentryTable,
			'allowEdit' => $allowEdit,
		));
	}

	private function showMenuList()
	{
		$allowedEdit = User::getAllowedLocations('ipxe.menu.edit');

		// TODO Permission::addGlobalTags($perms, null, ['edit.menu', 'edit.address', 'download']);

		$res = Database::simpleQuery("SELECT m.menuid, m.title, m.isdefault, GROUP_CONCAT(l.locationid) AS locations,
				GROUP_CONCAT(ll.locationname SEPARATOR ', ') AS locnames
				FROM serversetup_menu m
				LEFT JOIN serversetup_menu_location l USING (menuid)
				LEFT JOIN location ll USING (locationid)
				GROUP BY menuid
				ORDER BY title");
		$menuTable = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (empty($row['locations'])) {
				$locations = [];
				$row['allowEdit'] = in_array(0, $allowedEdit);
			} else {
				$locations = explode(',', $row['locations']);
				$row['allowEdit'] = in_array(0, $allowedEdit) || empty(array_diff($locations, $allowedEdit));
			}
			$row['locationCount'] = empty($locations) ? '' : count($locations);
			$menuTable[] = $row;
		}

		Render::addTemplate('menu-list', array(
			'menuTable' => $menuTable,
			'showSetDefault' => User::hasPermission('ipxe.menu.edit', 0)
		));
	}

	private function hasMenuPermission($menuid, $permission)
	{
		$allowedEditLocations = User::getAllowedLocations($permission);
		$allowEdit = in_array(0, $allowedEditLocations);
		if (!$allowEdit) {
			// Get locations
			$locations = Database::queryColumnArray('SELECT locationid FROM serversetup_menu_location
			WHERE menuid = :menuid', compact('menuid'));
			if (!empty($locations)) {
				$allowEdit = count(array_diff($locations, $allowedEditLocations)) === 0;
			}
		}
		return $allowEdit;
	}

	private function showEditMenu()
	{
		$id = Request::get('id', false, 'int');
		// if = edit, else = add new
		if ($id !== 0) {
			$menu = Database::queryFirst("SELECT menuid, timeoutms, title, defaultentryid, isdefault
			FROM serversetup_menu WHERE menuid = :id", compact('id'));
		} else {
			$menu = [];
			$menu['menuid'] = 0;
			$menu['timeoutms'] = 0;
			$menu['title'] = '';
			$menu['defaultentryid'] = null;
			$menu['isdefault'] = false;
		}

		if ($menu === false) {
			Message::addError('invalid-menu-id', $id);
			Util::redirect('?do=serversetup&show=menu');
		}
		$highlight = Request::get('highlight', false, 'string');
		if ($id !== 0 && !$this->hasMenuPermission($id, 'ipxe.menu.edit')) {
			$menu['readonly'] = 'readonly';
			$menu['disabled'] = 'disabled';
			$menu['plainpass'] = '';
		}
		if (!User::hasPermission('ipxe.menu.edit', 0)) {
			$menu['globalMenuWarning'] = true;
		}

		$menu['timeout'] = round($menu['timeoutms'] / 1000);
		$menu['entries'] = [];
		$res = Database::simpleQuery("SELECT menuentryid, entryid, refmenuid, hotkey, title, hidden, sortval, plainpass FROM
			serversetup_menuentry WHERE menuid = :id ORDER BY sortval ASC", compact('id'));
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['entryid'] === null && $row['refmenuid'] !== null) {
				$row['entryid'] = 'menu:' . $row['refmenuid'];
			}
			if ($row['entryid'] == $highlight) {
				$row['highlight'] = 'active';
			}
			$menu['entries'][] = $row;
		}
		$menu['keys'] = array_map(function ($item) { return ['key' => $item]; }, MenuEntry::getKeyList());
		$menu['entrylist'] = array_merge(
			Database::queryAll("SELECT entryid, title, hotkey, data FROM serversetup_bootentry ORDER BY title ASC"),
			// Add all menus, so we can link
			Database::queryAll("SELECT Concat('menu:', menuid) AS entryid, title FROM serversetup_menu ORDER BY title ASC")
		);
		class_exists('BootEntry'); // Leave this here for StandardBootEntry
		foreach ($menu['entrylist'] as &$bootentry) {
			if (!isset($bootentry['data']))
				continue;
			$bootentry['data'] = json_decode($bootentry['data'], true);
			// Transform stuff suitable for mustache
			if (!array_key_exists('arch', $bootentry['data']))
				continue;
			// BIOS/EFI or both
			if ($bootentry['data']['arch'] === StandardBootEntry::BIOS
					|| $bootentry['data']['arch'] === StandardBootEntry::BOTH) {
				$bootentry['data']['PCBIOS'] = array('executable' => $bootentry['data']['executable']['PCBIOS'],
					'initRd' => $bootentry['data']['initRd']['PCBIOS'],
					'commandLine' => $bootentry['data']['commandLine']['PCBIOS']);
			}
			if ($bootentry['data']['arch'] === StandardBootEntry::EFI
					|| $bootentry['data']['arch'] === StandardBootEntry::BOTH) {
				$bootentry['data']['EFI'] = array('executable' => $bootentry['data']['executable']['EFI'],
					'initRd' => $bootentry['data']['initRd']['EFI'],
					'commandLine' => $bootentry['data']['commandLine']['EFI']);
			}
			// Naming and agnostic
			if ($bootentry['data']['arch'] === StandardBootEntry::BIOS) {
				$bootentry['data']['arch'] = Dictionary::translateFile('template-tags','lang_biosOnly', true);
				unset($bootentry['data']['EFI']);
			} elseif ($bootentry['data']['arch'] === StandardBootEntry::EFI) {
				$bootentry['data']['arch'] = Dictionary::translateFile('template-tags','lang_efiOnly', true);
				unset($bootentry['data']['PCBIOS']);
			} elseif ($bootentry['data']['arch'] === StandardBootEntry::AGNOSTIC) {
				$bootentry['data']['archAgnostic'] = array('executable' => $bootentry['data']['executable']['PCBIOS'],
					'initRd' => $bootentry['data']['initRd']['PCBIOS'],
					'commandLine' => $bootentry['data']['commandLine']['PCBIOS']);
				$bootentry['data']['arch'] = Dictionary::translateFile('template-tags','lang_archAgnostic', true);
				unset($bootentry['data']['EFI']);
			} else {
				$bootentry['data']['arch'] = Dictionary::translateFile('template-tags','lang_archBoth', true);
			}
			foreach ($bootentry['data'] as &$e) {
				if (isset($e['initRd']) && is_array($e['initRd'])) {
					$e['initRd'] = implode(',', $e['initRd']);
				}
			}
		}
		foreach ($menu['entries'] as &$entry) {
			$entry['isdefault'] = ($entry['menuentryid'] == $menu['defaultentryid']);
			// TODO: plainpass only when permissions
		}

		Permission::addGlobalTags($menu['perms'], 0, ['ipxe.menu.edit']);
		Render::addTemplate('menu-edit', $menu);
	}

	private function showEditBootEntry()
	{
		$params = [];
		$id = Request::get('id', false, 'string');
		if ($id === false) {
			$params['exec_checked'] = 'checked';
			$params['entryid'] = 'u-' . dechex(mt_rand(0x1000, 0xffff)) . '-' . dechex(time());
			$params['entries'] = [
				['mode' => 'PCBIOS'],
				['mode' => 'EFI'],
			];
		} else {
			// Query existing entry
			$row = Database::queryFirst('SELECT entryid, title, builtin, data FROM serversetup_bootentry
				WHERE entryid = :id LIMIT 1', ['id' => $id]);
			if ($row === false) {
				Message::addError('invalid-boot-entry', $id);
				Util::redirect('?do=serversetup');
			}
			$entry = BootEntry::fromJson($row['data']);
			if ($entry === null) {
				Message::addError('unknown-bootentry-type', $id);
				Util::redirect('?do=serversetup');
			}
			$entry->addFormFields($params);
			$params['title'] = $row['title'];
			if (!Request::get('copy')) {
				$params['oldentryid'] = $params['entryid'] = $row['entryid'];
				$params['builtin'] = $row['builtin'];
			}
			if (!is_array($params['entries'])) {
				$params['entries'] = [];
			}
			$f = [];
			foreach ($params['entries'] as $e) {
				if (isset($e['mode'])) {
					$f[] = $e['mode'];
				}
			}
			if (!in_array('PCBIOS', $f)) {
				$params['entries'][] = ['mode' => 'PCBIOS'];
			}
			if (!in_array('EFI', $f)) {
				$params['entries'][] = ['mode' => 'EFI'];
			}
			$params['menus'] = Database::queryAll('SELECT m.menuid, m.title FROM serversetup_menu m
					INNER JOIN serversetup_menuentry me ON (me.menuid = m.menuid)
					WHERE me.entryid = :entryid', ['entryid' => $row['entryid']]);
		}

		$params['disabled'] = User::hasPermission('ipxe.bootentry.edit') ? '' : 'disabled';
		Render::addTemplate('ipxe-new-boot-entry', $params);
	}

	private function showEditAddress()
	{
		Render::addTemplate('ipaddress', array(
			'ips' => $this->addrListTask['data']['addresses'],
			'chooseHintClass' => $this->hasIpSet ? '' : 'alert alert-danger',
			'disabled' => ($this->getCompileTask() === false) ? '' : 'disabled',
		));
	}

	// -----------------------------------------------------------------------------------------------

	private function getLocalAddresses()
	{
		$this->addrListTask = Taskmanager::submit('LocalAddressesList', array());

		if ($this->addrListTask === false) {
			$this->addrListTask['data']['addresses'] = false;
			return false;
		}

		if (!Taskmanager::isFinished($this->addrListTask)) { // TODO: Async if just displaying
			$this->addrListTask = Taskmanager::waitComplete($this->addrListTask['id'], 4000);
		}

		if (Taskmanager::isFailed($this->addrListTask) || !isset($this->addrListTask['data']['addresses'])) {
			$this->addrListTask['data']['addresses'] = false;
			return false;
		}

		$sortIp = array();
		foreach (array_keys($this->addrListTask['data']['addresses']) as $key) {
			$item = & $this->addrListTask['data']['addresses'][$key];
			if (!isset($item['ip']) || !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $item['ip']) || substr($item['ip'], 0, 4) === '127.') {
				unset($this->addrListTask['data']['addresses'][$key]);
				continue;
			}
			if ($this->currentAddress === $item['ip']) {
				$item['default'] = true;
				$this->hasIpSet = true;
			}
			$sortIp[] = $item['ip'];
		}
		unset($item);
		array_multisort($sortIp, SORT_STRING, $this->addrListTask['data']['addresses']);
		return true;
	}

	private function deleteBootEntry()
	{
		$id = Request::post('deleteid', false, 'string');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'deleteid');
			return;
		}
		$res = Database::exec("DELETE FROM serversetup_bootentry
			WHERE entryid = :entryid AND builtin = 0", array("entryid" => $id));
		if ($res > 0) {
			Message::addSuccess('bootentry-deleted');
		}
		Util::redirect('?do=serversetup&show=bootentry');
	}

	private function setDefaultMenu()
	{
		$id = Request::post('menuid', false, 'int');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'menuid');
			return;
		}
		Database::exec('UPDATE serversetup_menu SET isdefault = (menuid = :menuid)', ['menuid' => $id]);
		Message::addSuccess('menu-set-default');
	}

	private function deleteMenu()
	{
		$id = Request::post('deleteid', false, 'int');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'deleteid');
			return;
		}
		if (!$this->hasMenuPermission($id, 'ipxe.menu.edit')) {
			Message::addError('locations.no-permission-location', $id);
			return;
		}
		Database::exec("DELETE FROM serversetup_menu WHERE menuid = :menuid", array("menuid" => $id));
		Message::addSuccess('menu-deleted');
	}

	private function saveMenu()
	{
		$id = Request::post('menuid', false, 'int');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'menuid');
			return;
		}

		$insertParams = [
			'title' => IPxe::sanitizeIpxeString(Request::post('title', '', 'string')),
			'timeoutms' => abs(Request::post('timeout', 0, 'int') * 1000),
		];
		if ($id === 0) {
			Database::exec("INSERT INTO serversetup_menu (title, timeoutms, isdefault) VALUES (:title, :timeoutms, 0)", $insertParams);
			$menu['menuid'] = $id = Database::lastInsertId();
		} else {
			$menu = Database::queryFirst("SELECT m.menuid
			FROM serversetup_menu m
			WHERE menuid = :id", compact('id'));
			if ($menu === false) {
				Message::addError('invalid-menu-id', $id);
				return;
			}
			$insertParams['menuid'] = $id;
			Database::exec('UPDATE serversetup_menu SET title = :title, timeoutms = :timeoutms
					WHERE menuid = :menuid', $insertParams);
		}

		$keepIds = [];
		$entries = Request::post('entry', false, 'array');
		$wantedDefaultEntryId = Request::post('defaultentry', null, 'string');
		$defaultEntryId = null;

		if ($entries) {
			foreach ($entries as $key => $entry) {
				if (!isset($entry['sortval'])) {
					error_log("Incomplete entry $key with: " . print_r($entry, true));
					continue;
				}
				// Fallback defaults
				$entry += [
					'entryid' => null,
					'title' => '',
					'hidden' => 0,
					'plainpass' => '',
				];
				$params = [
					'entryid' => null,
					'refmenuid' => null,
					'title' => IPxe::sanitizeIpxeString($entry['title']),
					'sortval' => (int)$entry['sortval'],
					'menuid' => $menu['menuid'],
				];
				if (empty($entry['entryid'])) {
					// Spacer
					$params += [
						'hotkey' => '',
						'hidden' => 0, // Doesn't make any sense
						'plainpass' => '', // Doesn't make any sense
					];
				} else {
					$params += [
						'hotkey' => MenuEntry::filterKeyName($entry['hotkey']),
						'hidden' => (int)$entry['hidden'], // TODO (needs hotkey to make sense)
						'plainpass' => $entry['plainpass'],
					];
					if (preg_match('/^menu:(\d+)$/', $entry['entryid'], $out)) {
						$params['refmenuid'] = $out[1];
					} else {
						$params['entryid'] = $entry['entryid'];
					}
				}
				if (is_numeric($key)) {
					if ((string)$key === $wantedDefaultEntryId) { // Check now that we have generated our key
						$defaultEntryId = $key;
					}
					$keepIds[] = $key;
					$params['menuentryid'] = $key;
					$params['md5pass'] = IPxe::makeMd5Pass($entry['plainpass'], $key);
					$ret = Database::exec('UPDATE serversetup_menuentry
					SET entryid = :entryid, refmenuid = :refmenuid, hotkey = :hotkey, title = :title, hidden = :hidden, sortval = :sortval,
						plainpass = :plainpass, md5pass = :md5pass
					WHERE menuid = :menuid AND menuentryid = :menuentryid', $params, true);
				} else {
					$ret = Database::exec("INSERT INTO serversetup_menuentry
					(menuid, entryid, refmenuid, hotkey, title, hidden, sortval, plainpass, md5pass)
					VALUES (:menuid, :entryid, :refmenuid, :hotkey, :title, :hidden, :sortval, :plainpass, '')", $params, true);
					if ($ret) {
						$newKey = Database::lastInsertId();
						if ((string)$key === $wantedDefaultEntryId) { // Check now that we have generated our key
							$defaultEntryId = $newKey;
						}
						$keepIds[] = (int)$newKey;
						if (!empty($entry['plainpass'])) {
							Database::exec('UPDATE serversetup_menuentry SET md5pass = :md5pass WHERE menuentryid = :id', [
								'md5pass' => IPxe::makeMd5Pass($entry['plainpass'], $newKey),
								'id' => $newKey,
							]);
						}
					}
				}

				if ($ret === false) {
					Message::addWarning('error-saving-entry', $entry['title'], Database::lastError());
				}
			}
			Database::exec('DELETE FROM serversetup_menuentry WHERE menuid = :menuid AND menuentryid NOT IN (:keep)',
				['menuid' => $menu['menuid'], 'keep' => $keepIds]);
			// Set default entry
			Database::exec('UPDATE serversetup_menu SET defaultentryid = :default WHERE menuid = :menuid',
				['menuid' => $menu['menuid'], 'default' => $defaultEntryId]);
		} else {
			Database::exec('DELETE FROM serversetup_menuentry WHERE menuid = :menuid', ['menuid' => $menu['menuid']]);
			Database::exec('UPDATE serversetup_menu SET defaultentryid = NULL WHERE menuid = :menuid', ['menuid' => $menu['menuid']]);
		}

		Message::addSuccess('menu-saved');
	}

	private function updateLocalAddress()
	{
		$newAddress = Request::post('ip', 'none', 'string');
		$valid = false;
		foreach ($this->addrListTask['data']['addresses'] as $item) {
			if ($item['ip'] !== $newAddress)
				continue;
			$valid = true;
			break;
		}
		if ($valid) {
			Property::setServerIp($newAddress);
			Util::redirect('?do=ServerSetup');
		} else {
			Message::addError('invalid-ip', $newAddress);
		}
		Util::redirect();
	}

	private function handleGetImage()
	{
		$file = "/opt/openslx/ipxe/openslx-bootstick.raw";
		if (!is_readable($file)) {
			Message::addError('image-not-found');
			return;
		}
		Header('Content-Type: application/octet-stream');
		Header('Content-Disposition: attachment; filename="openslx-bootstick-' . Property::getServerIp() . '-raw.img"');
		readfile($file);
		exit;
	}

	private function saveBootEntry()
	{
		$oldEntryId = Request::post('entryid', false, 'string');
		$newId = Request::post('newid', false, 'string');
		if (!preg_match('/^[a-z0-9\-_]{1,16}$/', $newId)) {
			Message::addError('main.parameter-empty', 'newid');
			return;
		}
		$data = Request::post('entry', false);
		if (!is_array($data)) {
			Message::addError('missing-bootentry-data');
			return;
		}
		$type = Request::post('type', false, 'string');
		if ($type === 'exec') {
			$entry = BootEntry::newStandardBootEntry($data);
		} elseif ($type === 'script') {
			$entry = BootEntry::newCustomBootEntry($data);
		} else {
			Message::addError('unknown-bootentry-type', $type);
			return;
		}
		if ($entry === null) {
			Message::addError('main.empty-field');
			Util::redirect('?do=serversetup&show=bootentry');
		}
		$params = [
			'entryid' => $newId,
			'title' => Request::post('title', '', 'string'),
			'data' => json_encode($entry->toArray()),
		];
		// New or update?
		if (empty($oldEntryId)) {
			// New entry
			Database::exec('INSERT INTO serversetup_bootentry (entryid, title, builtin, data)
				VALUES (:entryid, :title, 0, :data)', $params);
			Message::addSuccess('boot-entry-created', $newId);
		} else {
			// Edit existing entry
			$params['oldid'] = $oldEntryId;
			Database::exec('UPDATE serversetup_bootentry SET entryid = If(builtin = 0, :entryid, entryid), title = :title, data = :data
				WHERE entryid = :oldid', $params);
			Message::addSuccess('boot-entry-updated', $newId);
		}
		Util::redirect('?do=serversetup&show=bootentry');
	}

	private function showEditLocation()
	{
		$locationId = Request::get('locationid', false, 'int');
		$loc = Location::get($locationId);
		if ($loc === false) {
			Message::addError('locations.invalid-location-id', $locationId);
			return;
		}
		User::assertPermission('ipxe.menu.assign', $locationId);
		// List of menu entries
		$res = Database::simpleQuery('SELECT menuentryid, title FROM serversetup_menuentry');
		$menuEntries = $res->fetchAll(PDO::FETCH_KEY_PAIR);
		// List of menus
		$data = [
			'locationid' => $locationId,
			'locationName' => $loc['locationname'],
		];
		$menu = IPxeMenu::forLocation($loc['parentlocationid']);
		$data['defaultMenu'] = $menu->title();
		$res = Database::simpleQuery('SELECT m.menuid, m.title, ml.locationid, ml.defaultentryid, GROUP_CONCAT(me.menuentryid) AS entries FROM serversetup_menu m
				LEFT JOIN serversetup_menu_location ml ON (m.menuid = ml.menuid AND ml.locationid = :locationid)
				INNER JOIN serversetup_menuentry me ON (m.menuid = me.menuid AND me.entryid IS NOT NULL)
				GROUP BY menuid
				ORDER BY m.title ASC', ['locationid' => $locationId]);
		$menus = [];
		$hasDefault = false;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$eids = explode(',', $row['entries']);
			$row['entries'] = [];
			foreach ($eids as $eid) {
				$row['entries'][] = [
					'id' => $eid,
					'title' => $menuEntries[$eid],
					'selected' => ($eid == $row['defaultentryid'] ? 'selected' : ''),
				];
			}
			if ($row['locationid'] !== null) {
				$hasDefault = true;
				$row['menu_selected'] = 'checked';
			}
			$menus[] = $row;
		}
		if (!$hasDefault) {
			$data['default_selected'] = 'checked';
		}
		$data['list'] = $menus;
		Render::addTemplate('menu-assign-location', $data);
	}

	private function saveLocationMenu()
	{
		$locationId = Request::post('locationid', false, 'int');
		$loc = Location::get($locationId);
		if ($loc === false) {
			Message::addError('locations.invalid-location-id', $locationId);
			return;
		}
		User::assertPermission('ipxe.menu.assign', $locationId);
		$menuId = Request::post('menuid', false, 'int');
		if ($menuId === 0) {
			Database::exec('DELETE FROM serversetup_menu_location WHERE locationid = :locationid',
				['locationid' => $locationId]);
			Message::addSuccess('location-use-default', $loc['locationname']);
			return;
		}
		$defaultEntryId = Request::post('defaultentryid-' . $menuId, 0, 'int');
		if ($defaultEntryId === 0) {
			$defaultEntryId = null;
		}
		Database::exec('INSERT INTO serversetup_menu_location (menuid, locationid, defaultentryid)
				VALUES (:menuid, :locationid, :defaultentryid)
				ON DUPLICATE KEY UPDATE menuid = :menuid, defaultentryid = :defaultentryid', [
			'menuid' => $menuId,
			'locationid' => $locationId,
			'defaultentryid' => $defaultEntryId
		]);
		Message::addSuccess('location-menu-assigned', $loc['locationname']);
	}

	private function saveLocalboot()
	{
		$pcbios = Request::post('default-pcbios', 'SANBOOT', 'string');
		$efi = Request::post('default-efi', 'EXIT', 'string');
		if (!array_key_exists($pcbios, Localboot::BOOT_METHODS['PCBIOS'])) {
			Message::addError('localboot-invalid-method', $pcbios);
			return;
		}
		if (!array_key_exists($efi, Localboot::BOOT_METHODS['EFI'])) {
			Message::addError('localboot-invalid-method', $efi);
			return;
		}
		Localboot::setDefault($pcbios, $efi);
		$overrides = Request::post('override', [], 'array');
		Database::exec('TRUNCATE TABLE serversetup_localboot');
		foreach ($overrides as $model => $modes) {
			if (empty($modes)) // No override
				continue;
			$params = ['model' => urldecode($model), 'EFI' => null, 'PCBIOS' => null];
			foreach (['EFI', 'PCBIOS'] as $m) {
				if (empty($modes[$m]))
					continue;
				if (!array_key_exists($modes[$m], Localboot::BOOT_METHODS[$m])) {
					Message::addWarning('localboot-invalid-method', $modes[$m]);
					continue;
				}
				$params[$m] = $modes[$m];
			}
			if ($params['EFI'] === null && $params['PCBIOS'] === null)
				continue;
			Database::exec('INSERT INTO serversetup_localboot (systemmodel, pcbios, efi)
					VALUES (:model, :PCBIOS, :EFI)', $params);
		}
		Message::addSuccess('localboot-saved');
		Util::redirect('?do=serversetup&show=localboot');
	}

	private function execImportPxeMenu()
	{
		$content = Request::post('pxemenu', false, 'string');
		if (empty($content)) {
			Message::addError('main.parameter-empty', 'pxemenu');
			Util::redirect('?do=serversetup&show=import');
		}
		$menu = PxeLinux::parsePxeLinux($content, false);
		if (empty($menu->sections)) {
			Message::addWarning('import-no-entries');
			Util::redirect('?do=serversetup&show=import');
		}
		if (Request::post('entries-only', 0, 'int') !== 0) {
			$foo = [];
			IPxe::importPxeMenuEntries($menu, $foo);
			Util::redirect('?do=serversetup&show=bootentry');
		} else {
			$id = IPxe::insertMenu($menu, 'Imported Menu', false,  0, [], []);
			if ($id === false) {
				Message::addError('import-error');
				Util::redirect('?do=serversetup&show=import');
			} else {
				Util::redirect('?do=serversetup&show=editmenu&id=' . $id);
			}
		}
	}

}
