<?php

class LinuxBootEntryHook extends BootEntryHook
{

	public function name()
	{
		return Dictionary::translateFileModule('minilinux', 'module', 'module_name', true);
	}

	public function extraFields()
	{
		/* For translate module:
		 * Dictionary::translate('ipxe-kcl-extra');
		 * Dictionary::translate('ipxe-debug');
		 */
		return [
			new HookExtraField('kcl-extra', 'string', ''),
			new HookExtraField('debug', 'bool', false),
		];
	}

	/**
	 * @return HookEntryGroup[]
	 */
	protected function groupsInternal()
	{
		/*
		 * Dictionary::translate('default_boot_entry');
		 * Dictionary::translate('not_installed_hint');
		 */
		$array = [];
		$array[] = new HookEntryGroup($this->name(), [
			new HookEntry('default',
				Dictionary::translateFileModule('minilinux', 'module', 'default_boot_entry', true),
				MiniLinux::updateCurrentBootSetting())
		]);
		$branches = Database::queryAll('SELECT sourceid, branchid, title FROM minilinux_branch ORDER BY title');
		$versions = MiniLinux::queryAllVersionsByBranch();
		// Group by branch for detailed listing
		foreach ($branches as $branch) {
			if (isset($versions[$branch['branchid']])) {
				$group = [];
				foreach ($versions[$branch['branchid']] as $version) {
					$valid = $version['installed'] != 0;
					$title = $version['versionid'] . ' ' . $version['title'];
					if (!$valid) {
						$title .= ' ' . Dictionary::translateFileModule('minilinux', 'module', 'not_installed_hint');
					}
					$group[] = new HookEntry($version['versionid'], $title, $valid);
				}
				$array[] = new HookEntryGroup($branch['title'] ? $branch['title'] : $branch['branchid'], $group);
			}
		}
		return $array;
	}

	/**
	 * @param $id
	 * @return BootEntry the actual boot entry instance for given entry, false if invalid id
	 */
	public function getBootEntryInternal($data)
	{
		$id = $data['id'];
		if ($id === 'default') { // Special case
			$effectiveId = Property::get(MiniLinux::PROPERTY_DEFAULT_BOOT_EFFECTIVE);
		} else {
			$effectiveId = $id;
		}
		$res = Database::queryFirst('SELECT installed, data FROM minilinux_version WHERE versionid = :id', ['id' => $effectiveId]);
		if ($res === false) {
			return BootEntry::newCustomBootEntry(['script' => 'prompt Invalid minilinux boot entry id: ' . $id]);
		}
		if ($res['installed'] == 0) {
			return BootEntry::newCustomBootEntry(['script' => 'prompt Selected version not currently installed on server: ' . $id]);
		}
		$exec = new ExecData();
		// Defaults
		$root = '/boot/' . $id . '/';
		$exec->executable = 'kernel';
		$exec->initRd = ['initramfs-stage31'];
		$exec->imageFree = true;
		$exec->commandLine = 'slxbase=boot/%ID% slxsrv=${serverip} quiet splash ${ipappend1} ${ipappend2}';
		// Overrides
		$remoteData = json_decode($res['data'], true);
		// TODO: agnostic hard coded, support EFI and PCBIOS
		if (isset($remoteData['agnostic']) && is_array($remoteData['agnostic'])) {
			foreach (['executable', 'commandLine', 'initRd', 'imageFree'] as $key) {
				if (isset($remoteData['agnostic'][$key])) {
					$exec->{$key} = $remoteData['agnostic'][$key];
				}
			}
		}
		unset($rd);
		// KCL hacks
		if (isset($data['debug']) && $data['debug']) {
			if (!isset($data['kcl-extra'])) {
				$data['kcl-extra'] = '';
			}
			$data['kcl-extra'] = '-quiet -splash -loglevel loglevel=7 ' . $data['kcl-extra'];
		}
		if (isset($data['kcl-extra'])) {
			$items = preg_split('/\s+/', $data['kcl-extra'], -1, PREG_SPLIT_NO_EMPTY);
			// TODO: Make this a function, somewhere in serversetup-ipxe, this could be useful for other stuff
			foreach ($items as $item) {
				if ($item{0} === '-') {
					$item = preg_quote(substr($item, 1), '/');
					$exec->commandLine = preg_replace('/(^|\s)' . $item . '(=\S*)?($|\s)/', ' ', $exec->commandLine);
				} else {
					$exec->commandLine .= ' ' . $item;
				}
			}
		}
		$exec->commandLine = str_replace('%ID%', $id, $exec->commandLine);
		$exec->executable = $root . $exec->executable;
		foreach ($exec->initRd as &$rd) {
			$rd = $root . $rd;
		}
		return BootEntry::newStandardBootEntry($exec, false, 'agnostic');
	}

	public function isValidId($id)
	{
		$res = Database::queryFirst('SELECT installed FROM minilinux_version WHERE versionid = :id', ['id' => $id]);
		return $res !== false && $res['installed'];
	}
}

return new LinuxBootEntryHook();