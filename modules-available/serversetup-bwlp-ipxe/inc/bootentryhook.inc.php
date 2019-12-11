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

	private $data = [];

	/**
	 * @return string
	 */
	public abstract function name();

	/**
	 * @return HookExtraField[]
	 */
	public abstract function extraFields();

	/**
	 * @param string $id
	 * @return bool
	 */
	public abstract function isValidId($id);

	/**
	 * @return HookEntryGroup[]
	 */
	protected abstract function groupsInternal();

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

	/**
	 * @param $id
	 * @return BootEntry|null the actual boot entry instance for given entry, null if invalid id
	 */
	public abstract function getBootEntryInternal($localData);

	public final function getBootEntry($data)
	{
		if (!is_array($data)) {
			$data = json_decode($data, true);
		}
		return $this->getBootEntryInternal($data);
	}

	/**
	 * @param string $mixed either the plain ID if the entry to be marked as selected, or the JSON string representing
	 *          the entire entry, which must have a key called 'id' that will be used as the ID then.
	 */
	public function setSelected($mixed)
	{
		$json = @json_decode($mixed, true);
		if (is_array($json)) {
			$id = $json['id'];
			$this->data = $json;
		} else {
			$id = $mixed;
		}
		$this->selectedId = $id;
	}

	/**
	 * @return string ID of entry that was marked as selected by setSelected()
	 */
	public function getSelected()
	{
		return $this->selectedId;
	}

	public function renderExtraFields()
	{
		$list = $this->extraFields();
		foreach ($list as &$entry) {
			$entry->currentValue = isset($this->data[$entry->name]) ? $this->data[$entry->name] : $entry->default;
			$entry->hook = $this;
		}
		return $list;
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
	 * @var bool
	 */
	public $valid;
	/**
	 * @var string if !valid, this will be the string 'disabled', empty otherwise
	 */
	public $disabled;
	/**
	 * @var string internal - to be set by ipxe module
	 */
	public $selected;

	/**
	 * HookEntry constructor.
	 *
	 * @param string $id
	 * @param string $name
	 * @param bool $valid
	 */
	public function __construct($id, $name, $valid)
	{
		$this->id = $id;
		$this->name = $name;
		$this->valid = $valid;
		$this->disabled = $valid ? '' : 'disabled';
	}
}

class HookExtraField
{
	/**
	 * @var string ID of extra field, [a-z0-9\-] please. Must not be 'id'
	 */
	public $name;
	/**
	 * @var string type of field, use string, bool, or an array of predefined options
	 */
	public $type;
	/**
	 * @var mixed default value
	 */
	public $default;

	public $currentValue;

	/**
	 * @var BootEntryHook
	 */
	public $hook;

	public function __construct($name, $type, $default)
	{
		$this->name = $name;
		$this->type = $type;
		$this->default = $default;
	}

	public function fromPost($typePrefix)
	{
		if (is_array($this->type)) {
			$val = Request::post('extra-' . $typePrefix . '-' . $this->name, '', 'array');
			if (!in_array($val, $this->type)) {
				$val = $this->default;
			}
		} else {
			$val = Request::post('extra-' . $typePrefix . '-' . $this->name, '', $this->type);
			settype($val, $this->type);
		}
		return $val;
	}

	public function html()
	{
		$fieldId = 'extra-' . $this->hook->moduleId . '-' . $this->name;
		$fieldText = htmlspecialchars(Dictionary::translateFileModule($this->hook->moduleId, 'module', 'ipxe-' . $this->name, true));
		if (is_array($this->type)) {
			$out = '<label for="' . $fieldId . '">' . $fieldText . '</label><select class="form-control" name="' . $fieldId . '" id="' . $fieldId . '">';
			foreach ($this->type as $entry) {
				$selected = ($entry === $this->currentValue) ? 'selected' : '';
				$out .= '<option ' . $selected . '>' . htmlspecialchars($entry) . '</option>';
			}
			$out .= '</select>';
			return $out;
		}
		if ($this->type === 'bool') {
			$checked = $this->currentValue ? 'checked' : '';
			return '<div class="checkbox"><input type="checkbox" id="' . $fieldId
				. '" name="' . $fieldId . '" ' . $checked . '><label for="' . $fieldId . '">'
				. $fieldText . '</label></div>';
		}
		// Default
		return '<label for="' . $fieldId . '">' . $fieldText . '</label>'
			. '<input class="form-control" type="text" id="' . $fieldId
			. '" name="' . $fieldId . '" value="' . htmlspecialchars($this->currentValue) . '">';
	}

}