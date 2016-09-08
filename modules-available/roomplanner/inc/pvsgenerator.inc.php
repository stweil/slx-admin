<?php

define('X', 0);
define('Y', 1);

class PvsGenerator
{

	public static function generate()
	{

		if (!Module::isAvailable('locations')) {
			die('sorry, but the locations module is required');
		}


		/* get all rooms */
		$rooms = array();
		$ret = Database::simpleQuery(
			'SELECT l.locationid, l.locationname, lr.managerip, lr.tutoruuid, m.clientip as tutorip '
			. 'FROM location l '
			. 'INNER JOIN location_roomplan lr ON (l.locationid = lr.locationid)'
			. 'LEFT JOIN machine m ON (lr.tutoruuid = m.machineuuid)');
		while ($row = $ret->fetch(PDO::FETCH_ASSOC)) {
			if (Location::isLeaf($row['locationid'])) { // TODO: This creates extra queries, optimize?
				$row['locationname'] = str_replace(',', ';', $row['locationname']); // comma probably not the best sep here
				$rooms[] = $row;
			}
		}
		/* collect names */
		$roomNames = array();

		foreach ($rooms as $room) {
			$roomNames[] = $room['locationname'];
		}

		/* add [General]-block */
		$config = "[General]\n";
		$config .= 'rooms=' . implode(', ', $roomNames) . "\n";
		$config .= "allowClientQuit=True\n"; // TODO: remove this
		$config .= "showLockDesktopButton=True\n"; // TODO: Make this configurable (or not)
		$config .= "\n\n";

		/* foreach room generate room-block */
		foreach ($rooms as $room) {
			$config .= PvsGenerator::generateRoomBlock($room);
			$config .= "\n";
		}

		return $config;
	}

	private static function generateRoomBlock($room)
	{
		$out = '[' . $room['locationname'] . "]\n";


		/* find all clients in that room */
		$machines = PvsGenerator::getMachines($room['locationid']);
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

		return $out;
	}

	private static function generateGrid($machines)
	{
		$out = "";

		/* this is a virtual grid, we first need this to do some optimizations */
		$grid = array();
		/* add each contained client with position and ip */
		foreach ($machines as $machine) {
			$grid[$machine['clientip']] = [$machine['gridCol'], $machine['gridRow']];
		}
		/* find bounding box */
		PvsGenerator::boundingBox($grid, $minX, $minY, $maxX, $maxY);
		$clientSizeX = 4; /* TODO: optimize */
		$clientSizeY = 4; /* TODO: optimize */
		$sizeX = max($maxX - $minX + $clientSizeX, 1); /* never negative */
		$sizeY = max($maxY - $minY + $clientSizeY, 1); /* and != 0 to avoid divide-by-zero in pvsmgr */

		/* zoom all clients into bounding box */
		foreach ($grid as $ip => $pos) {
			$newX = $grid[$ip][X] - $minX;
			$newY = $grid[$ip][Y] - $minY;
			$grid[$ip] = [$newX, $newY];
		}

		$out .= "gridSize=@Size($sizeX $sizeY)\n";
		$out .= "clientSize=@Size($clientSizeX $clientSizeY)\n";
		$out .= "client\\size=" . count($grid) . "\n";

		$i = 1;
		foreach ($grid as $ip => $pos) {
			$out .= "client\\" . $i . "\\ip=$ip\n";
			$out .= "client\\" . $i++ . "\\pos=@Point(" . $pos[X] . ' ' . $pos[Y] . ")\n";
		}

		return $out;

	}


	private static function getMachines($roomid)
	{
		$ret = Database::simpleQuery(
			'SELECT clientip, position FROM machine WHERE locationid = :locationid',
			['locationid' => $roomid]);

		$machines = array();

		while ($row = $ret->fetch(PDO::FETCH_ASSOC)) {
			$position = json_decode($row['position'], true);

			$machine = array();
			$machine['clientip'] = $row['clientip'];
			$machine['gridRow'] = $position['gridRow'];
			$machine['gridCol'] = $position['gridCol'];
			$machine['tutor'] = false; /* TODO: find out if machine is default tutor */
			$machine['manager'] = false; /* TODO: find out if machine is manager */

			$machines[] = $machine;
		}

		return $machines;

	}

	private static function boundingBox($grid, &$minX, &$minY, &$maxX, &$maxY)
	{
		$minX = PHP_INT_MAX; /* PHP_INT_MIN is only avaiable since PHP 7 */
		$maxX = ~PHP_INT_MAX;
		$minY = PHP_INT_MAX;
		$maxY = ~PHP_INT_MAX;

		foreach ($grid as $pos) {
			$minX = min($minX, $pos[X]);
			$maxX = max($maxX, $pos[X]);
			$minY = min($minY, $pos[Y]);
			$maxY = max($maxY, $pos[Y]);
		}
	}

}

