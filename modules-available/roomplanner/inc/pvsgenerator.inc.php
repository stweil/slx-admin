<?php

define('X', 0);
define('Y', 1);

class PvsGenerator
{

	public static function generate()
	{
		/* get all rooms */
		$rooms = array();
		// Use left joins everywhere so we still have the complete list of locations below
		// for figuring out which locations are leafs and which aren't
		$ret = Database::simpleQuery(
			'SELECT l.locationid, l.parentlocationid, l.locationname, lr.locationid AS notnull, lr.managerip, lr.tutoruuid, m.clientip as tutorip '
			. 'FROM location l '
			. 'LEFT JOIN location_roomplan lr ON (l.locationid = lr.locationid)'
			. 'LEFT JOIN machine m ON (lr.tutoruuid = m.machineuuid)');
		while ($row = $ret->fetch(PDO::FETCH_ASSOC)) {
			$row['locationname'] = str_replace(',', ';', $row['locationname']); // comma probably not the best sep here
			settype($row['locationid'], 'int');
			settype($row['parentlocationid'], 'int');
			$rooms[$row['locationid']] = $row;
		}
		// Mark all non-leafs as skip
		foreach ($rooms as &$room) {
			if ($room['parentlocationid'] > 0 && isset($rooms[$room['parentlocationid']])) {
				$rooms[$room['parentlocationid']]['skip'] = true; // Don't just unset, might be wrong order
			}
		}
		// Now un-mark all where there's at least one child without valid room plan
		foreach ($rooms as &$room) {
			if (!isset($room['skip']) && (is_null($room['notnull']) || empty($room['managerip']))) {
				$room['skip'] = true;
				$r2 =& $room;
				while ($r2['parentlocationid'] > 0) {
					$r2 =& $rooms[$r2['parentlocationid']];
					if (!(is_null($room['notnull']) || empty($room['managerip']))) {
						unset($r2['skip']);
						break;
					}
				}
			}
		}
		unset($room, $r2); // refd!

		/* collect names and build room blocks - filter empty rooms while at it */
		$roomNames = array();
		$roomBlocks = '';
		foreach ($rooms as $room) {
			if (is_null($room['notnull']) || isset($room['skip'])) // Not leaf
				continue;
			if (Module::isAvailable('runmode')) {
				$pc = RunMode::getForMode('roomplanner', $room['locationid'], true);
				if (!empty($pc)) {
					$pc = array_pop($pc);
					$room['managerip'] = $pc['clientip'];
				}
			}
			if (empty($room['managerip'])) // rooms without managerips don't make sense
				continue;
			$roomBlock = PvsGenerator::generateRoomBlock($room);
			if ($roomBlock === false)
				continue; // Room nonexistent or empty
			$roomNames[] = md5($room['locationname']);
			$roomBlocks .= $roomBlock;
		}

		/* output room plus [General]-block */
		return "[General]\n"
		. 'rooms=' . implode(', ', $roomNames) . "\n"
		. "allowClientQuit=False\n" // TODO: configurable
		. "showLockDesktopButton=True\n" // TODO: Make this configurable (or not)
		. "\n\n"
		. $roomBlocks;
	}

	/**
	 * Generate .ini section for specific room.
	 *
	 * @param $room array room/location data as fetched from db
	 * @return string|bool .ini section for room, or false if room is empty
	 */
	private static function generateRoomBlock($room)
	{
		$out = '[' . md5($room['locationname']) . "]\n";


		/* find all clients in that room */
		$machines = PvsGenerator::getMachines($room['locationid']);
		if (empty($machines))
			return false;

		$out .= "name=" . $room['locationname'] . "\n";

		/* manager */
		$mgr = $room['managerip'];
		$tutor = $room['tutorip'];
		if ($mgr) {
			$out .= 'mgrIP=' . $mgr . "\n";
		}
		/* tutor */
		if ($tutor) {
			$out .= 'tutorIP=' . $tutor . "\n";
		}

		/* grid */
		$out .= PvsGenerator::generateGrid($machines);

		return $out . "\n";
	}

	/**
	 * Generate grid size information and client position data for given clients.
	 *
	 * @param $machines array list of clients
	 * @return string grid and position data as required for a room's .ini section
	 */
	private static function generateGrid($machines)
	{
		$out = "";

		/* find bounding box */
		PvsGenerator::boundingBox($machines, $minX, $minY, $maxX, $maxY);
		$clientSizeX = 4; /* TODO: optimize */
		$clientSizeY = 4; /* TODO: optimize */
		$sizeX = max($maxX - $minX + $clientSizeX, 1); /* never negative */
		$sizeY = max($maxY - $minY + $clientSizeY, 1); /* and != 0 to avoid divide-by-zero in pvsmgr */

		/* output basic settings for this room */
		$out .= "gridSize=@Size($sizeX $sizeY)\n";
		$out .= "clientSize=@Size($clientSizeX $clientSizeY)\n";
		$out .= "client\\size=" . count($machines) . "\n";

		/* output individual client positions, shift coordinates to origin */
		$i = 1;
		foreach ($machines as $pos) {
			$out .= "client\\$i\\ip={$pos['clientip']}\n";
			$out .= "client\\$i\\pos=@Point(" . ($pos['gridCol'] - $minX) . ' ' . ($pos['gridRow'] - $minY) . ")\n";
			$i++;
		}

		return $out;
	}

	/**
	 * Render given location's room plan as SVG.
	 * If locationId is given, show roomplan for that location.
	 * If additionally, machineUuid is given, try to highlight
	 * the given machine in the plan. If only machineUuid is
	 * given, determine locationId from machine.
	 *
	 * @param int|false $locationId
	 * @param string|false $highlightUuid
	 * @param int $rotate rotate plan (0-3 for N E S W up, -1 for "auto" if highlightUuid is given)
	 * @return string SVG
	 */
	public static function generateSvg($locationId = false, $highlightUuid = false, $rotate = 0)
	{
		if ($locationId === false) {
			$locationId = Database::queryFirst('SELECT fixedlocationid FROM machine
					WHERE machineuuid = :uuid AND Length(position) > 5',
				['uuid' => $highlightUuid]);
			// Not found or not placed in room plan -- bail out
			if ($locationId === false || $locationId['fixedlocationid'] === null)
				return false;
			$locationId = $locationId['fixedlocationid'];
		}
		$machines = self::getMachines($locationId);
		if (empty($machines))
			return false;

		$ORIENTATION = ['north' => 2, 'east' => 3, 'south' => 0, 'west' => 1];
		if (is_string($highlightUuid)) {
			$highlightUuid = strtoupper($highlightUuid);
		}
		// Figure out autorotate
		$auto = ($rotate < 0);
		if ($auto && $highlightUuid !== false) {
			foreach ($machines as &$machine) {
				if ($machine['machineuuid'] === $highlightUuid) {
					$rotate = $ORIENTATION[$machine['rotation']];
					break;
				}
			}
		}
		$rotate %= 4;
		// Highlight given machine, rotate it's "keyboard"
		foreach ($machines as &$machine) {
			if ($machine['machineuuid'] === $highlightUuid) {
				$machine['class'] = 'hl';
			}
			$machine['rotation'] = $ORIENTATION[$machine['rotation']] * 90;
		}
		PvsGenerator::boundingBox($machines, $minX, $minY, $maxX, $maxY);
		$clientSizeX = 4; /* TODO: optimize */
		$clientSizeY = 4; /* TODO: optimize */
		$minX--;
		$minY--;
		$maxX++;
		$maxY++;
		$sizeX = max($maxX - $minX + $clientSizeX, 1); /* never negative */
		$sizeY = max($maxY - $minY + $clientSizeY, 1); /* and != 0 to avoid divide-by-zero in pvsmgr */
		if ($rotate === 0) {
			$centerY = $centerX = 0;
		} elseif ($rotate === 1) {
			$centerX = $minX + min($sizeX, $sizeY) / 2;
			$centerY = $minY + min($sizeX, $sizeY) / 2;
			self::swap($sizeX, $sizeY);
		} elseif ($rotate === 2) {
			$centerX = $minX + $sizeX / 2;
			$centerY = $minY + $sizeY / 2;
		} else {
			$centerX = $minX + max($sizeX, $sizeY) / 2;
			$centerY = $minY + max($sizeX, $sizeY) / 2;
			self::swap($sizeX, $sizeY);
		}
		return Render::parse('svg-plan', [
			'width' => $sizeX,
			'height' => $sizeY,
			'centerX' => $centerX,
			'centerY' => $centerY,
			'rotate' => $rotate * 90,
			'shiftX' => -$minX,
			'shiftY' => -$minY,
			'machines' => $machines,
			'line' => ['x1' => $minX, 'y1' => $maxY + $clientSizeY,
				'x2' => $maxX + $clientSizeX, 'y2' => $maxY + $clientSizeY],
		], 'roomplanner'); // FIXME: Needs module param if called from api.inc.php
	}

	private static function swap(&$a, &$b)
	{
		$tmp = $a;
		$a = $b;
		$b = $tmp;
	}

	/**
	 * Get all clients for given room with IP and position.
	 *
	 * @param $roomid int locationid of room
	 * @return array
	 */
	private static function getMachines($roomid)
	{
		$ret = Database::simpleQuery(
			'SELECT machineuuid, clientip, position FROM machine WHERE fixedlocationid = :locationid',
			['locationid' => $roomid]);

		$machines = array();

		while ($row = $ret->fetch(PDO::FETCH_ASSOC)) {
			$position = json_decode($row['position'], true);

			if ($position === false || !isset($position['gridRow']) || !isset($position['gridCol']))
				continue; // TODO: Remove entry/set to NULL?

			$rotation = 'north';
			if (preg_match('/(north|east|south|west)/', $position['itemlook'], $out)) {
				$rotation = $out[1];
			}
			$machines[] = array(
				'machineuuid' => $row['machineuuid'],
				'clientip' => $row['clientip'],
				'gridRow' => $position['gridRow'],
				'gridCol' => $position['gridCol'],
				'rotation' => $rotation,
			);
		}

		return $machines;

	}

	private static function boundingBox($machines, &$minX, &$minY, &$maxX, &$maxY)
	{
		$minX = PHP_INT_MAX; /* PHP_INT_MIN is only available since PHP 7 */
		$maxX = ~PHP_INT_MAX;
		$minY = PHP_INT_MAX;
		$maxY = ~PHP_INT_MAX;

		foreach ($machines as $pos) {
			$minX = min($minX, $pos['gridCol']);
			$maxX = max($maxX, $pos['gridCol']);
			$minY = min($minY, $pos['gridRow']);
			$maxY = max($maxY, $pos['gridRow']);
		}
	}

	public static function runmodeConfigHook($machineUuid, $locationId, $data)
	{
		if (!empty($data)) {
			$data = json_decode($data, true);
		}
		if (!is_array($data)) {
			$data = array();
		}

		if (isset($data['dedicatedmgr']) && $data['dedicatedmgr']) {
			ConfigHolder::add("SLX_ADDONS", false, 100000);
			ConfigHolder::add("SLX_PVS_DEDICATED", 'yes');
			ConfigHolder::add("SLX_AUTOLOGIN", 'ON', 100000);
		} else {
			ConfigHolder::add("SLX_PVS_HYBRID", 'yes');
		}
	}

	/**
	 * Get display name for manager of given locationId.
	 * @param $locationId
	 * @return bool|string
	 */
	public static function getManagerName($locationId)
	{
		$names = Location::getNameChain($locationId);
		if ($names === false)
			return false;
		return implode(' / ', $names);
	}

}
