<?php

class ComposedRoom
{
	/**
	 * @var string How to compose contained rooms. Value is either horizontal or vertical.
	 */
	public $orientation = 'horizontal';

	/**
	 * @var int[] Order in which contained rooms are composed. List of locationid.
	 */
	public $list;

	/**
	 * @var bool Whether composed room is active, ie. visible in PVS.
	 */
	public $enabled;

	/**
	 * @var int locationid of contained room that is the controlling room;
	 */
	public $controlRoom;

	public function __construct($data)
	{
		if ($data instanceof ComposedRoom) {
			foreach ($data as $k => $v) {
				$this->{$k} = $v;
			}
		} else {
			if (is_array($data) && isset($data['roomplan'])) {
				// From DB
				$data = json_decode($data['roomplan'], true);
			} elseif (is_string($data)) {
				// Just JSON
				$data = json_decode($data, true);
			}
			if (is_array($data)) {
				foreach ($this as $k => $v) {
					if (isset($data[$k])) {
						$this->{$k} = $data[$k];
					}
				}
			}
		}
		$this->sanitize();
	}

	/**
	 * Make sure all member vars have the proper type
	 */
	private function sanitize()
	{
		$this->orientation = ($this->orientation === 'horizontal' ? 'horizontal' : 'vertical');
		settype($this->enabled, 'bool');
		settype($this->list, 'array');
		settype($this->controlRoom, 'int');
		foreach ($this->list as &$v) {
			settype($v, 'int');
		}
		$this->list = array_values($this->list);
		if (!empty($this->list) && !in_array($this->controlRoom, $this->list)) {
			$this->controlRoom = $this->list[0];
		}
	}

	/**
	 * @return false|string JSON
	 */
	public function serialize()
	{
		$this->sanitize();
		return json_encode($this);
	}

}
