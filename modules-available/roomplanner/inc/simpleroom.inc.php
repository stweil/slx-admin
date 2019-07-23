<?php

class SimpleRoom extends Room
{

	const CLIENT_SIZE = 4;

	private $machines = [];

	private $bb = false;

	private $tutorIp = false;

	private $managerIp = false;

	public function __construct($row)
	{
		parent::__construct($row);
		$locationId = (int)$row['locationid'];
		$ret = Database::simpleQuery(
			'SELECT machineuuid, clientip, position FROM machine WHERE fixedlocationid = :locationid',
			['locationid' => $locationId]);

		while ($clientRow = $ret->fetch(PDO::FETCH_ASSOC)) {
			$position = json_decode($clientRow['position'], true);

			if ($position === false || !isset($position['gridRow']) || !isset($position['gridCol']))
				continue; // TODO: Remove entry/set to NULL?

			$rotation = 'north';
			if (preg_match('/(north|east|south|west)/', $position['itemlook'], $out)) {
				$rotation = $out[1];
			}
			$this->machines[] = array(
				'machineuuid' => $clientRow['machineuuid'],
				'clientip' => $clientRow['clientip'],
				'gridRow' => $position['gridRow'],
				'gridCol' => $position['gridCol'],
				'rotation' => $rotation,
			);
		}
		// Runmode info overrides IP given
		if (Module::isAvailable('runmode')) {
			$pc = RunMode::getForMode('roomplanner', $locationId, true);
			if (!empty($pc)) {
				$pc = array_pop($pc);
				$row['managerip'] = $pc['clientip'];
			}
		}
		if (!empty($row['managerip'])) {
			$this->managerIp = $row['managerip'];
		}
		if (!empty($row['tutorip'])) {
			$this->tutorIp = $row['tutorip'];
		}
	}

	public function machineCount()
	{
		return count($this->machines);
	}

	public function getSize(&$width, &$height)
	{
		if (empty($this->machines)) {
			$width = $height = 0;
			return;
		}
		$this->boundingBox($minX, $minY, $maxX, $maxY);
		// client's size that cannot be configured as of today
		$width = max($maxX - $minX + self::CLIENT_SIZE, 1);
		$height = max($maxY - $minY + self::CLIENT_SIZE, 1);
	}

	public function getIniClientSection(&$i, $offX = 0, $offY = 0)
	{
		/* output individual client positions, shift coordinates to requested position */
		$out = '';
		$this->boundingBox($minX, $minY, $maxX, $maxY);
		foreach ($this->machines as $pos) {
			$i++;
			$out .= "client\\$i\\ip={$pos['clientip']}\n"
				. "client\\$i\\pos=@Point(" . ($pos['gridCol'] + $offX -$minX) . ' ' . ($pos['gridRow'] + $offY - $minY) . ")\n";
		}

		return $out;
	}

	public function getShiftedArray($offX = 0, $offY = 0)
	{
		/* output individual client positions, shift coordinates to requested position */
		$ret = [];
		$this->boundingBox($minX, $minY, $maxX, $maxY);
		foreach ($this->machines as $pos) {
			$pos['gridCol'] += $offX - $minX;
			$pos['gridRow'] += $offY - $minY;
			$ret[] = $pos;
		}

		return $ret;
	}

	private function boundingBox(&$minX, &$minY, &$maxX, &$maxY)
	{
		if ($this->bb !== false) {
			$minX = $this->bb[0];
			$minY = $this->bb[1];
			$maxX = $this->bb[2];
			$maxY = $this->bb[3];
		} else {
			$minX = $minY = PHP_INT_MAX; /* PHP_INT_MIN is only available since PHP 7 */
			$maxX = $maxY = ~PHP_INT_MAX;
			foreach ($this->machines as $pos) {
				$minX = min($minX, $pos['gridCol']);
				$maxX = max($maxX, $pos['gridCol']);
				$minY = min($minY, $pos['gridRow']);
				$maxY = max($maxY, $pos['gridRow']);
			}
			$this->bb = [$minX, $minY, $maxX, $maxY];
		}
	}

	public function getManagerIp()
	{
		return $this->managerIp;
	}

	public function getTutorIp()
	{
		return $this->tutorIp;
	}

	public function isLeaf()
	{
		return true;
	}

	public function shouldSkip()
	{
		return empty($this->machines);
	}

	protected function sanitize()
	{
		// Nothing
	}

}
