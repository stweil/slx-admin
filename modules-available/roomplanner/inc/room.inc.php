<?php

abstract class Room
{

	/**
	 * @var Room[] list of all rooms
	 */
	protected static $rooms = null;

	/**
	 * @var int id for this room
	 */
	private $locationId;

	/**
	 * @var string name of this room
	 */
	private $locationName;

	protected static function init()
	{
		if (self::$rooms !== null)
			return;
		/* get all rooms */
		self::$rooms = [];
		$ret = Database::simpleQuery(
			'SELECT lr.locationid, lr.managerip, lr.tutoruuid, lr.roomplan, m.clientip as tutorip
					FROM location_roomplan lr
					LEFT JOIN machine m ON (lr.tutoruuid = m.machineuuid)');
		while ($row = $ret->fetch(PDO::FETCH_ASSOC)) {
			$row = self::loadSingleRoom($row);
			if ($row === false)
				continue;
			self::$rooms[$row->locationId] = $row;
		}
		foreach (self::$rooms as $room) {
			$room->sanitize();
		}
	}

	/**
	 * Instantiate ComposedRoom or MachineGroup depending on contents of $row
	 * @param array $row DB row from location_roomplan.
	 * @return Room|false Room instance, false on error
	 */
	private static function loadSingleRoom($row)
	{
		$locations = Location::getLocationsAssoc();
		settype($row['locationid'], 'int');
		if (!isset($locations[$row['locationid']]))
			return false;
		if ($locations[$row['locationid']]['isleaf'])
			return new SimpleRoom($row);
		return new ComposedRoom($row, false);
	}

	/**
	 * Get array of all rooms with room plan
	 * @return Room[]
	 */
	public static function getAll()
	{
		self::init();
		return self::$rooms;
	}

	/**
	 * Get room instance for given location
	 * @param int $locationId room to get
	 * @return Room|false requested room, false if not configured or not found
	 */
	public static function get($locationId)
	{
		if (self::$rooms === null) {
			$room = Database::queryFirst(
				'SELECT lr.locationid, lr.managerip, lr.tutoruuid, lr.roomplan, m.clientip as tutorip
					FROM location_roomplan lr
					LEFT JOIN machine m ON (lr.tutoruuid = m.machineuuid)
					WHERE lr.locationid = :lid', ['lid' => $locationId]);
			if ($room === false)
				return false;
			$room = self::loadSingleRoom($room);
			// If it's a leaf room we probably don't need any other rooms, return it
			if ($room->isLeaf())
				return $room;
			// Otherwise init the full tree so we can resolve composed rooms later
			self::init();
		}
		if (isset(self::$rooms[$locationId]))
			return self::$rooms[$locationId];
		return false;
	}

	public function __construct($row)
	{
		$locations = Location::getLocationsAssoc();
		$this->locationId = (int)$row['locationid'];
		$this->locationName = $locations[$this->locationId]['locationname'];
	}

	/**
	 * @return int number of machines in this room
	 */
	abstract public function machineCount();

	/**
	 * Size of this room, returned by reference.
	 * @param int $width OUT width of room
	 * @param int $height OUT height of room
	 */
	abstract public function getSize(&$width, &$height);

	/**
	 * Get clients in this room in .ini format for PVS.
	 * Adjusted so the top/left client is at (0|0), which
	 * is further adjustable with $offX and $offY.
	 * @param int $i offset for indexing clients
	 * @param int $offX positional X offset for clients
	 * @param int $offY positional Y offset for clients
	 * @return string|false
	 */
	abstract public function getIniClientSection(&$i, $offX = 0, $offY = 0);

	/**
	 * Get clients in this room as array.
	 * Adjusted so the top/left client is at (0|0), which
	 *is further adjustable with $offX and $offY.
	 * @param int $offX
	 * @param int $offY
	 * @return array
	 */
	abstract public function getShiftedArray($offX = 0, $offY = 0);

	/**
	 * @return string|false IP address of manager.
	 */
	abstract public function getManagerIp();

	/**
	 * @return string|false IP address of tutor client.
	 */
	abstract public function getTutorIp();

	/**
	 * @return bool true if this is a simple/leaf room, false for composed rooms.
	 */
	abstract public function isLeaf();

	/**
	 * @return bool should this room be skipped from output? true for empty SimpleRoom or disabled ComposedRoom.
	 */
	abstract public function shouldSkip();

	/**
	 * Sanitize this room's data.
	 */
	abstract protected function sanitize();

	/**
	 * @return string get room's name.
	 */
	public function locationName()
	{
		return $this->locationName;
	}

	/**
	 * @return int get room's id.
	 */
	public function locationId()
	{
		return $this->locationId;
	}

}