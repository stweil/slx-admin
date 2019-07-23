<?php

class ComposedRoom extends Room
{
	/**
	 * @var string How to compose contained rooms. Value is either horizontal or vertical.
	 */
	private $orientation = 'horizontal';

	/**
	 * @var int[] Order in which contained rooms are composed. List of locationid.
	 */
	private $list;

	/**
	 * @var bool Whether composed room is active, ie. visible in PVS.
	 */
	private $enabled;

	/**
	 * @var int locationid of contained room that is the controlling room;
	 */
	private $controlRoom;

	/**
	 * ComposedRoom constructor.
	 *
	 * @param array|true $row DB row to instantiate from, or true to read from $_POST
	 */
	public function __construct($row, $sanitize = true)
	{
		if ($row === true) {
			$this->orientation = Request::post('orientation', 'horizontal', 'string');
			$this->enabled = (bool)Request::post('enabled', 0, 'int');
			$this->controlRoom = Request::post('controlroom', 0, 'int');
			$vals = Request::post('sort', [], 'array');
			asort($vals, SORT_ASC | SORT_NUMERIC);
			$this->list = array_keys($vals);
		} else {
			parent::__construct($row);
			if (is_array($row) && isset($row['roomplan'])) {
				// From DB
				$row = json_decode($row['roomplan'], true);
			}
			if (is_array($row)) {
				foreach ($this as $k => $v) {
					if (isset($row[$k])) {
						$this->{$k} = $row[$k];
					}
				}
			}
		}
		if ($sanitize) {
			$this->sanitize();
		}
	}

	/**
	 * Make sure all member vars have the proper type
	 */
	protected function sanitize()
	{
		$this->orientation = ($this->orientation === 'horizontal' ? 'horizontal' : 'vertical');
		settype($this->enabled, 'bool');
		settype($this->list, 'array');
		settype($this->controlRoom, 'int');
		self::init();
		//error_log('List: ' . print_r($this->list, true));
		//error_log('Rooms: ' . print_r(self::$rooms, true));
		$old = $this->list;
		$this->list = [];
		foreach ($old as $v) {
			settype($v, 'int');
			if (isset(self::$rooms[$v])) {
				$this->list[] = $v;
			}
		}
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
		$out = [];
		foreach ($this as $k => $v) {
			$out[$k] = $v;
		}
		return json_encode($out);
	}

	public function orientation()
	{
		return $this->orientation;
	}

	public function subLocationIds()
	{
		return $this->list;
	}

	public function controlRoom()
	{
		return $this->controlRoom;
	}

	public function machineCount()
	{
		$sum = 0;
		foreach ($this->list as $lid) {
			$sum += self::$rooms[$lid]->machineCount();
		}
		return $sum;
	}

	public function getSize(&$width, &$height)
	{
		$horz = ($this->orientation == 'horizontal');
		$width = $height = 0;
		foreach ($this->list as $locId) {
			self::$rooms[$locId]->getSize($w, $h);
			$width = $horz ? $width + $w : max($width, $w);
			$height = !$horz ? $height + $h : max($height, $h);
		}
	}

	public function getIniClientSection(&$i, $offX = 0, $offY = 0)
	{
		if (!$this->enabled)
			return false;
		if ($this->orientation == 'horizontal') {
			$x = 1;
			$y = 0;
		} else {
			$x = 0;
			$y = 1;
		}
		$out = '';
		foreach ($this->list as $locId) {
			$ret = self::$rooms[$locId]->getIniClientSection($i, $offX, $offY);
			if ($ret !== false) {
				$out .= $ret;
				self::$rooms[$locId]->getSize($w, $h);
				$offX += $w * $x;
				$offY += $h * $y;
			}
		}
		if (empty($out))
			return false;
		return $out;
	}

	public function getShiftedArray($offX = 0, $offY = 0)
	{
		if (!$this->enabled)
			return false;
		if ($this->orientation == 'horizontal') {
			$x = 1;
			$y = 0;
		} else {
			$x = 0;
			$y = 1;
		}
		$ret = [];
		foreach ($this->list as $locId) {
			$new = self::$rooms[$locId]->getShiftedArray($offX, $offY);
			if ($new !== false) {
				$ret = array_merge($ret, $new);
				self::$rooms[$locId]->getSize($w, $h);
				$offX += $w * $x;
				$offY += $h * $y;
			}
		}
		if (empty($ret))
			return false;
		return $ret;
	}

	public function getManagerIp()
	{
		if (isset(self::$rooms[$this->controlRoom]))
			return self::$rooms[$this->controlRoom]->getManagerIp();
		return false;
	}

	public function getTutorIp()
	{
		if (isset(self::$rooms[$this->controlRoom]))
			return self::$rooms[$this->controlRoom]->getTutorIp();
		return false;
	}

	public function isLeaf()
	{
		return false;
	}

	public function shouldSkip()
	{
		return !$this->enabled;
	}
}
