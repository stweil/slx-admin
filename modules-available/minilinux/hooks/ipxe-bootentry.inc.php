<?php

class Wurst extends BootEntryHook
{

	public function name()
	{
		return 'Wurst';
	}

	/**
	 * @return HookEntryGroup[]
	 */
	protected function groupsInternal()
	{
		return [
			new HookEntryGroup('Senf Gruppe', [
				new HookEntry('senf-1', 'Senf v1'),
				new HookEntry('senf-2', 'Senf v2'),
			]),
			new HookEntryGroup('Schnecke Gruppe', [
				new HookEntry('s-1', 'Trulla'),
				new HookEntry('s-2', 'Herbert'),
			]),
		];
	}

	/**
	 * @param $id
	 * @return BootEntry the actual boot entry instance for given entry, false if invalid id
	 */
	public function getBootEntry($id)
	{
		$bios = new ExecData();
		$bios->executable = 'mspaint.exe';
		$bios->initRd = 'www.google.de';
		$bios->commandLine = '-q';
		return BootEntry::newStandardBootEntry($bios, false, 'agnostic');
	}
}

return new Wurst();