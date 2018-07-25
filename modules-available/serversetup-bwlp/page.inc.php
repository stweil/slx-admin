<?php

class Page_ServerSetup extends Page
{

	private $taskStatus;
	private $currentAddress;
	private $currentMenu;
	private $hasIpSet = false;

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		if (Request::any('bla') == 'blu') {
			IPxe::importLegacyMenu();
			IPxe::importPxeMenus('/srv/openslx/tftp/pxelinux.cfg');
			die('DONE');
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

		if ($action === 'ip') {
			User::assertPermission("edit.address");
			// New address is to be set
			$this->getLocalAddresses();
			$this->updateLocalAddress();
		}

		if ($action === 'ipxe') {
			User::assertPermission("edit.menu");
			// iPXE stuff changes
			$this->updatePxeMenu();
		}

		if ($action === 'savebootentry') {
			User::assertPermission('ipxe.bootentry.edit');
			$this->saveBootEntry();
		}

		if ($action === 'deleteBootentry') {
			User::assertPermission('ipxe.bootentry.delete');
			$this->deleteBootEntry();
		}

		if ($action === 'savemenu') {
			User::assertPermission('ipxe.menu.edit');
			$this->saveMenu();
		}

		if ($action === 'deleteMenu') {
			User::assertPermission('ipxe.menu.delete');
			$this->deleteMenu();
		}

		if (Request::isPost()) {
			Util::redirect('?do=serversetup');
		}

		User::assertPermission('access-page');

		if (User::hasPermission('ipxe.*')) {
			Dashboard::addSubmenu('?do=serversetup&show=menu', Dictionary::translate('submenu_menu', true));
			Dashboard::addSubmenu('?do=serversetup&show=bootentry', Dictionary::translate('submenu_bootentry', true));
		}
		if (User::hasPermission('edit.address')) {
			Dashboard::addSubmenu('?do=serversetup&show=address', Dictionary::translate('submenu_address', true));
		}
		if (User::hasPermission('download')) {
			Dashboard::addSubmenu('?do=serversetup&show=download', Dictionary::translate('submenu_download', true));
		}
		if (Request::get('show') === false) {
			$subs = Dashboard::getSubmenus();
			if (empty($subs)) {
				User::assertPermission('download');
			} else {
				Util::redirect($subs[0]['url']);
			}
		}
	}

	protected function doRender()
	{
		Render::addTemplate("heading");

		$task = Property::get('ipxe-task-id');
		if ($task !== false) {
			$task = Taskmanager::status($task);
			if (!Taskmanager::isTask($task) || Taskmanager::isFinished($task)) {
				$task = false;
			}
		}
		if ($task !== false) {
			Render::addTemplate('ipxe_update', array('taskid' => $task['id']));
		}

		switch (Request::get('show')) {
		case 'editbootentry':
			User::assertPermission('ipxe.bootentry.edit');
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
		default:
			Util::redirect('?do=serversetup');
			break;
		}
	}

	private function showDownload()
	{
		// TODO: Make nicer, support more variants (taskmanager-plugin)
		Render::addTemplate('download');
	}

	private function showBootentryList()
	{
		$allowEdit = User::hasPermission('ipxe.bootentry.edit');
		$allowDelete = User::hasPermission('ipxe.bootentry.delete');
		$allowAdd = 'disabled';
		if (User::hasPermission('ipxe.bootentry.add')) {
			$allowAdd = '';
		}

		$res = Database::simpleQuery("SELECT entryid, hotkey, title FROM serversetup_bootentry");
		$bootentryTable = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$bootentryTable[] = $row;
		}

		Render::addTemplate('bootentry-list', array(
			'bootentryTable' => $bootentryTable,
			'allowAdd' => $allowAdd,
			'allowEdit' => $allowEdit,
			'allowDelete' => $allowDelete
		));
	}

	private function showMenuList()
	{
		$allowedEdit = User::getAllowedLocations('ipxe.menu.edit');
		$allowedDelete = User::getAllowedLocations('ipxe.menu.delete');

		// TODO Permission::addGlobalTags($perms, null, ['edit.menu', 'edit.address', 'download']);

		$res = Database::simpleQuery("SELECT m.menuid, m.title, m.isdefault, GROUP_CONCAT(l.locationid) AS locations
			FROM serversetup_menu m LEFT JOIN serversetup_menu_location l USING (menuid) GROUP BY menuid ORDER BY title");
		$menuTable = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (empty($row['locations'])) {
				$locations = [];
				$row['allowEdit'] = in_array(0, $allowedEdit);
				$row['allowDelete'] = in_array(0, $allowedDelete);
			} else {
				$locations = explode(',', $row['locations']);
				$row['allowEdit'] = empty(array_diff($locations, $allowedEdit));
				$row['allowDelete'] = empty(array_diff($locations, $allowedDelete));
			}
			$row['locationCount'] = empty($locations) ? '' : count($locations);
			$menuTable[] = $row;
		}

		$allowAddMenu = 'disabled';
		if (User::hasPermission('ipxe.menu.add')) {
			$allowAddMenu = '';
		}

		Render::addTemplate('menu-list', array(
			'menuTable' => $menuTable,
			'allowAddMenu' => $allowAddMenu
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
		if (!$this->hasMenuPermission($id, 'ipxe.menu.edit')) {
			$menu['readonly'] = 'readonly';
			$menu['disabled'] = 'disabled';
			$menu['plainpass'] = '';
		}
		$menu['timeout'] = round($menu['timeoutms'] / 1000);
		$menu['entries'] = Database::queryAll("SELECT menuentryid, entryid, hotkey, title, hidden, sortval, plainpass FROM
			serversetup_menuentry WHERE menuid = :id ORDER BY sortval ASC", compact('id'));
		$menu['keys'] = array_map(function ($item) { return ['key' => $item]; }, MenuEntry::getKeyList());
		$menu['entrylist'] = Database::queryAll("SELECT entryid, title, hotkey FROM serversetup_bootentry ORDER BY title ASC");
		foreach ($menu['entries'] as &$entry) {
			$entry['isdefault'] = ($entry['menuentryid'] == $menu['defaultentryid']);
			// TODO: plainpass only when permissions
		}
		// TODO: Make assigned locations editable
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
				Message::addError('unknown-boot-entry-type', $id);
				Util::redirect('?do=serversetup');
			}
			$entry->addFormFields($params);
			$params['title'] = $row['title'];
			$params['oldentryid'] = $params['entryid'] = $row['entryid'];
		}
		Render::addTemplate('ipxe-new-boot-entry', $params);
	}

	// -----------------------------------------------------------------------------------------------

	private function getLocalAddresses()
	{
		$this->taskStatus = Taskmanager::submit('LocalAddressesList', array());

		if ($this->taskStatus === false) {
			$this->taskStatus['data']['addresses'] = false;
			return false;
		}

		if (!Taskmanager::isFinished($this->taskStatus)) { // TODO: Async if just displaying
			$this->taskStatus = Taskmanager::waitComplete($this->taskStatus['id'], 4000);
		}

		if (Taskmanager::isFailed($this->taskStatus) || !isset($this->taskStatus['data']['addresses'])) {
			$this->taskStatus['data']['addresses'] = false;
			return false;
		}

		$sortIp = array();
		foreach (array_keys($this->taskStatus['data']['addresses']) as $key) {
			$item = & $this->taskStatus['data']['addresses'][$key];
			if (!isset($item['ip']) || !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $item['ip']) || substr($item['ip'], 0, 4) === '127.') {
				unset($this->taskStatus['data']['addresses'][$key]);
				continue;
			}
			if ($this->currentAddress === $item['ip']) {
				$item['default'] = true;
				$this->hasIpSet = true;
			}
			$sortIp[] = $item['ip'];
		}
		unset($item);
		array_multisort($sortIp, SORT_STRING, $this->taskStatus['data']['addresses']);
		return true;
	}

	private function deleteBootEntry() {
		$id = Request::post('deleteid', false, 'string');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'deleteid');
			return;
		}
		Database::exec("DELETE FROM serversetup_bootentry WHERE entryid = :entryid", array("entryid" => $id));
		// TODO: Redirect to &show=bootentry
		Message::addSuccess('bootentry-deleted');
	}

	private function deleteMenu()
	{
		$id = Request::post('deleteid', false, 'int');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'deleteid');
			return;
		}
		if (!$this->hasMenuPermission($id, 'ipxe.menu.delete')) {
			Message::addError('locations.no-permission-location', 'TODO');
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
		// TODO: Validate new locations to be saved (and actually save them)
		$insertParams = [
			'title' => IPxe::sanitizeIpxeString(Request::post('title', '', 'string')),
			'timeoutms' => abs(Request::post('timeout', 0, 'int') * 1000),
		];
		if ($id === 0) {
			Database::exec("INSERT INTO serversetup_menu (title, timeoutms) VALUES (:title, :timeoutms)", $insertParams);
			$menu['menuid'] = $id = Database::lastInsertId();
		} else {
			$menu = Database::queryFirst("SELECT m.menuid, GROUP_CONCAT(l.locationid) AS locations
			FROM serversetup_menu m
			LEFT JOIN serversetup_menu_location l USING (menuid)
			WHERE menuid = :id", compact('id'));
			if ($menu === false) {
				Message::addError('no-such-menu', $id);
				return;
			}
			if (!$this->hasMenuPermission($id, 'ipxe.menu.edit')) {
				Message::addError('locations.no-permission-location', 'TODO');
				return;
			}
			$insertParams['menuid'] = $id;
			Database::exec('UPDATE serversetup_menu SET title = :title, timeoutms = :timeoutms
					WHERE menuid = :menuid', $insertParams);
		}

		if (User::hasPermission('ipxe.menu.edit', 0)
				&& Request::post('defmenu', false, 'boolean')) {
			Database::exec('UPDATE serversetup_menu SET isdefault = (menuid = :menuid)', ['menuid' => $id]);
		}

		$keepIds = [];
		$entries = Request::post('entry', false, 'array');
		$wantedDefaultEntryId = Request::post('defaultentry', null, 'string');
		$defaultEntryId = null;

		foreach ($entries as $key => $entry) {
			if (!isset($entry['sortval'])) {
				error_log(print_r($entry, true));
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
				'title' => IPxe::sanitizeIpxeString($entry['title']),
				'sortval' => (int)$entry['sortval'],
				'menuid' => $menu['menuid'],
			];
			if (empty($entry['entryid'])) {
				// Spacer
				$params += [
					'entryid' => null,
					'hotkey' => '',
					'hidden' => 0, // Doesn't make any sense
					'plainpass' => '', // Doesn't make any sense
				];
			} else {
				$params += [
					'entryid' => $entry['entryid'], // TODO validate?
					'hotkey' => MenuEntry::filterKeyName($entry['hotkey']),
					'hidden' => (int)$entry['hidden'], // TODO (needs hotkey to make sense)
					'plainpass' => $entry['plainpass'],
				];
			}
			if (is_numeric($key)) {
				if ((string)$key === $wantedDefaultEntryId) { // Check now that we have generated our key
					$defaultEntryId = $key;
				}
				$keepIds[] = $key;
				$params['menuentryid'] = $key;
				$params['md5pass'] = IPxe::makeMd5Pass($entry['plainpass'], $key);
				$ret = Database::exec('UPDATE serversetup_menuentry
					SET entryid = :entryid, hotkey = :hotkey, title = :title, hidden = :hidden, sortval = :sortval,
						plainpass = :plainpass, md5pass = :md5pass
					WHERE menuid = :menuid AND menuentryid = :menuentryid', $params, true);
			} else {
				$ret = Database::exec("INSERT INTO serversetup_menuentry
					(menuid, entryid, hotkey, title, hidden, sortval, plainpass, md5pass)
					VALUES (:menuid, :entryid, :hotkey, :title, :hidden, :sortval, :plainpass, '')", $params, true);
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
		Message::addSuccess('menu-saved');
	}

	private function updateLocalAddress()
	{
		$newAddress = Request::post('ip', 'none');
		$valid = false;
		foreach ($this->taskStatus['data']['addresses'] as $item) {
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

	private function updatePxeMenu()
	{
		$timeout = Request::post('timeout', 10);
		if ($timeout === '')
			$timeout = 0;
		if (!is_numeric($timeout) || $timeout < 0) {
			Message::addError('main.value-invalid', 'timeout', $timeout);
		}
		$this->currentMenu['defaultentry'] = Request::post('defaultentry', 'net');
		$this->currentMenu['timeout'] = $timeout;
		$this->currentMenu['custom'] = Request::post('custom', '');
		$this->currentMenu['masterpasswordclear'] = Request::post('masterpassword', '');
		if (empty($this->currentMenu['masterpasswordclear']))
			$this->currentMenu['masterpassword'] = 'invalid';
		else
			$this->currentMenu['masterpassword'] = Crypto::hash6($this->currentMenu['masterpasswordclear']);
		Property::setBootMenu($this->currentMenu);
		Trigger::ipxe();
		Util::redirect('?do=ServerSetup');
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
			Message::addError('missing-entry-data');
			return;
		}
		$type = Request::post('type', false, 'string');
		if ($type === 'exec') {
			$entry = BootEntry::newStandardBootEntry($data);
		} elseif ($type === 'script') {
			$entry = BootEntry::newCustomBootEntry($data);
		} else {
			Message::addError('unknown-entry-type', $type);
			return;
		}
		if ($entry === null) {
			Message::addError('main.empty-field');
			return;
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
			Database::exec('UPDATE serversetup_bootentry SET entryid = :entryid, title = :title, data = :data
				WHERE entryid = :oldid AND builtin = 0', $params);
			// TODO: Redirect to &show=bootentry
			Message::addSuccess('boot-entry-updated', $newId);
		}
	}

}
