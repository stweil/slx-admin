<?php

class PvsGenerator
{

	public static function generate()
	{
		/* collect names and build room blocks - filter empty rooms while at it */
		$roomNames = array();
		$roomBlocks = '';
		$rooms = Room::getAll();
		foreach ($rooms as $room) {
			if ($room->shouldSkip())
				continue;
			if ($room->getManagerIp() === false) // No .ini entry for rooms without manager (do we want this?)
				continue;
			$roomBlock = PvsGenerator::generateRoomBlock($room);
			if ($roomBlock === false)
				continue; // Room nonexistent or empty
			$section = substr(md5($room->locationId() . '-' . $room->locationName()), 0, 10);
			$roomNames[] = $section;
			$roomBlocks .= "[$section]\n" . $roomBlock;
		}

		/* output room plus [General]-block */
		return "[General]\n"
		. 'rooms=' . implode(', ', $roomNames) . "\n"
		. "allowClientQuit=False\n" // TODO: configurable
		. "\n\n"
		. $roomBlocks;
	}

	/**
	 * Generate .ini section for specific room.
	 *
	 * @param Room $room room/location data as fetched from db
	 * @return string|false .ini section for room, or false if room is empty
	 */
	private static function generateRoomBlock($room)
	{
		$room->getSize($sizeX, $sizeY);
		if ($sizeX === 0 || $sizeY === 0)
			return false;
		$count = 0;
		$section = $room->getIniClientSection($count);
		if ($section === false)
			return false;

		$cs = SimpleRoom::CLIENT_SIZE;
		$out = "name=" . $room->locationName() . "\n";

		/* manager */
		$out .= 'mgrIP=' . $room->getManagerIp() . "\n";
		/* tutor */
		if ($room->getTutorIp() !== false) {
			$out .= 'tutorIP=' . $room->getTutorIp() . "\n";
		}

		/* basic settings for this room */
		$out .= "gridSize=@Size($sizeX $sizeY)\n";
		$out .= "clientSize=@Size($cs $cs)\n";
		$out .= "client\\size=$count\n";

		/* output with grid */
		return $out . $section . "\n";
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
	 * @param float $scale scaling factor for output
	 * @return string SVG
	 */
	public static function generateSvg($locationId = false, $highlightUuid = false, $rotate = 0, $scale = 1)
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
		// Load room
		$room = Room::get($locationId);
		if ($room === false)
			return false;
		$room->getSize($sizeX, $sizeY);
		if ($sizeX === 0 || $sizeY === 0)
			return false; // Empty

		$machines = $room->getShiftedArray();
		$ORIENTATION = ['north' => 2, 'east' => 3, 'south' => 0, 'west' => 1];
		if (is_string($highlightUuid)) {
			$highlightUuid = strtoupper($highlightUuid);
		}
		// Figure out autorotate
		$auto = ($rotate < 0);
		if ($auto && $highlightUuid !== false) {
			foreach ($machines as &$machine) {
				if ($machine['machineuuid'] === $highlightUuid) {
					$rotate = 4 - $ORIENTATION[$machine['rotation']]; // Reverse rotation
					break;
				}
			}
		}
		$rotate %= 4;
		// Highlight given machine, rotate its "keyboard"
		foreach ($machines as &$machine) {
			if ($machine['machineuuid'] === $highlightUuid) {
				$machine['class'] = 'hl';
			}
			$machine['rotation'] = $ORIENTATION[$machine['rotation']] * 90;
		}
		if ($rotate === 0) {
			$centerY = $centerX = 0;
		} elseif ($rotate === 1) {
			$centerY = $centerX = $sizeY / 2;
			self::swap($sizeX, $sizeY);
		} elseif ($rotate === 2) {
			$centerX = $sizeX / 2;
			$centerY = $sizeY / 2;
		} else {
			$centerY = $centerX = $sizeX / 2;
			self::swap($sizeX, $sizeY);
		}
		return Render::parse('svg-plan', [
			'scale' => $scale,
			'width' => $sizeX * $scale,
			'height' => $sizeY * $scale,
			'centerX' => $centerX,
			'centerY' => $centerY,
			'rotate' => $rotate * 90,
			'machines' => $machines,
			'line' => ['x' => $sizeX, 'y' => $sizeY],
		], 'roomplanner'); // FIXME: Needs module param if called from api.inc.php
	}

	private static function swap(&$a, &$b)
	{
		$tmp = $a;
		$a = $b;
		$b = $tmp;
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
	 * Hook for "runmode" module to resolve mode name.
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
