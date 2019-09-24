<?php

abstract class BootEntryHook
{

	/**
	 * @var string -- set by ipxe, not module implementing hook
	 */
	public $moduleId;
	/**
	 * @var string -- set by ipxe, not module implementing hook
	 */
	public $checked;

	private $selectedId;

	public abstract function name();

	/**
	 * @return HookEntryGroup[]
	 */
	protected abstract function groupsInternal();

	/**
	 * @param $id
	 * @return BootEntry|null the actual boot entry instance for given entry, null if invalid id
	 */
	public abstract function getBootEntry($id);

	/**
	 * @return HookEntryGroup[]
	 */
	public final function groups()
	{
		$groups = $this->groupsInternal();
		foreach ($groups as $group) {
			foreach ($group->entries as $entry) {
				if ($entry->id === $this->selectedId) {
					$entry->selected = 'selected';
				}
			}
		}
		return $groups;
	}

	public function setSelected($id)
	{
		$this->selectedId = $id;
	}

}

class HookEntryGroup
{
	/**
	 * @var string
	 */
	public $groupName;
	/**
	 * @var HookEntry[]
	 */
	public $entries;

	public function __construct($groupName, $entries)
	{
		$this->groupName = $groupName;
		$this->entries = $entries;
	}
}

class HookEntry
{
	/**
	 * @var string
	 */
	public $id;
	/**
	 * @var string
	 */
	public $name;
	/**
	 * @var string internal - to be set by ipxe module
	 */
	public $selected;

	public function __construct($id, $name)
	{
		$this->id = $id;
		$this->name = $name;
	}
}