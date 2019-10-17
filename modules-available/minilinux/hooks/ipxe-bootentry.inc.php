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
	public function getBootEntryInternal($localData)
	{
		$id = $localData['id'];
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
			return BootEntry::newCustomBootEntry(['script' => 'prompt Selected version not currently installed on server: ' . $effectiveId]);
		}
		$remoteData = json_decode($res['data'], true);
		$bios = $efi = false;
		if (!@is_array($remoteData['agnostic']) && !@is_array($remoteData['efi']) && !@is_array($remoteData['bios'])) {
			$remoteData['agnostic'] = []; // We got nothing at all so fake this entry, resulting in a generic default entry
		}
		if (@is_array($remoteData['agnostic'])) {
			$bios = $this->generateExecData($effectiveId, $remoteData['agnostic'], $localData);
			$arch = BootEntry::AGNOSTIC;
		} else {
			if (@is_array($remoteData['efi'])) {
				$efi = $this->generateExecData($effectiveId, $remoteData['efi'], $localData);
			}
			if (@is_array($remoteData['bios'])) {
				$bios = $this->generateExecData($effectiveId, $remoteData['bios'], $localData);
			}
			if ($bios && $efi) {
				$arch = BootEntry::BOTH;
			} elseif ($bios) {
				$arch = BootEntry::BIOS;
			} else {
				$arch = BootEntry::EFI;
			}
		}
		return BootEntry::newStandardBootEntry($bios, $efi, $arch);
	}

	private function generateExecData($effectiveId, $remoteData, $localData)
	{
		$exec = new ExecData();
		// Defaults
		$root = '/boot/' . $effectiveId . '/';
		$exec->executable = 'kernel';
		$exec->initRd = ['initramfs-stage31'];
		$exec->imageFree = true;
		$exec->commandLine = 'slxbase=boot/%ID% slxsrv=${serverip} quiet splash ${ipappend1} ${ipappend2}';
		// Overrides
		foreach (['executable', 'commandLine', 'initRd', 'imageFree'] as $key) {
			if (isset($remoteData[$key])) {
				$exec->{$key} = $remoteData[$key];
			}
		}
		// KCL hacks
		if (isset($localData['debug']) && $localData['debug']) {
			$exec->commandLine = IPxe::modifyCommandLine($exec->commandLine,
				isset($remoteData['debugCommandLineModifier'])
				? $remoteData['debugCommandLineModifier']
				: '-vga -quiet -splash -loglevel loglevel=7'
			);
		}
		if (isset($localData['kcl-extra'])) {
			$exec->commandLine = IPxe::modifyCommandLine($exec->commandLine, $localData['kcl-extra']);
		}
		$exec->commandLine = str_replace('%ID%', $effectiveId, $exec->commandLine);
		$exec->executable = $root . $exec->executable;
		foreach ($exec->initRd as &$rd) {
			$rd = $root . $rd;
		}
		unset($rd);
		return $exec;
	}

	public function isValidId($id)
	{
		$res = Database::queryFirst('SELECT installed FROM minilinux_version WHERE versionid = :id', ['id' => $id]);
		return $res !== false && $res['installed'];
	}
}

return new LinuxBootEntryHook();