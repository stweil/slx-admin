<?php

class Parser {
	public static function parseCpu(&$row, $data)
	{
		if (0 >= preg_match_all('/^(.+):\s+(\d+)$/im', $data, $out, PREG_SET_ORDER)) {
			return;
		}
		foreach ($out as $entry) {
			$row[str_replace(' ', '', $entry[1])] = $entry[2];
		}
	}

	public static function parseDmiDecode(&$row, $data)
	{
		$lines = preg_split("/[\r\n]+/", $data);
		$section = false;
		$ramOk = false;
		$ramForm = $ramType = $ramSpeed = $ramClockSpeed = false;
		foreach ($lines as $line) {
			if (empty($line)) {
				continue;
			}
			if ($line{0} !== "\t" && $line{0} !== ' ') {
				$section = $line;
				$ramOk = false;
				if (($ramForm || $ramType) && ($ramSpeed || $ramClockSpeed)) {
					if (isset($row['ramtype']) && !$ramClockSpeed) {
						continue;
					}
					$row['ramtype'] = $ramType . ' ' . $ramForm;
					if ($ramClockSpeed) {
						$row['ramtype'] .= ', ' . $ramClockSpeed;
					} elseif ($ramSpeed) {
						$row['ramtype'] .= ', ' . $ramSpeed;
					}
					$ramForm = false;
					$ramType = false;
					$ramClockSpeed = false;
				}
				continue;
			}
			if ($section === 'Base Board Information') {
				if (preg_match('/^\s*Product Name: +(\S.+?) *$/i', $line, $out)) {
					$row['mobomodel'] = $out[1];
				}
				if (preg_match('/^\s*Manufacturer: +(\S.+?) *$/i', $line, $out)) {
					$row['mobomanufacturer'] = $out[1];
				}
			} elseif ($section === 'System Information') {
				if (preg_match('/^\s*Product Name: +(\S.+?) *$/i', $line, $out)) {
					$row['pcmodel'] = $out[1];
				}
				if (preg_match('/^\s*Manufacturer: +(\S.+?) *$/i', $line, $out)) {
					$row['pcmanufacturer'] = $out[1];
				}
			} elseif ($section === 'Physical Memory Array') {
				if (!$ramOk && preg_match('/Use: System Memory/i', $line)) {
					$ramOk = true;
				}
				if ($ramOk && preg_match('/^\s*Number Of Devices: +(\S.+?) *$/i', $line, $out)) {
					$row['ramslotcount'] = $out[1];
				}
				if ($ramOk && preg_match('/^\s*Maximum Capacity: +(\S.+?)\s*$/i', $line, $out)) {
					$row['maxram'] = preg_replace('/([MGT])B/', '$1iB', $out[1]);
				}
			} elseif ($section === 'Memory Device') {
				if (preg_match('/^\s*Size:\s*(.*?)\s*$/i', $line, $out)) {
					$row['extram'] = true;
					if (preg_match('/(\d+)\s*(\w)i?B/i', $out[1], $out)) {
						$out[2] = strtoupper($out[2]);
						if ($out[2] === 'K' || ($out[2] === 'M' && $out[1] < 500)) {
							$ramForm = $ramType = $ramSpeed = $ramClockSpeed = false;
							continue;
						}
						if ($out[2] === 'M' && $out[1] >= 1024) {
							$out[2] = 'G';
							$out[1] = floor(($out[1] + 100) / 1024);
						}
						$row['ramslot'][]['size'] = $out[1] . ' ' . strtoupper($out[2]) . 'iB';
					} elseif (!isset($row['ramslot']) || (count($row['ramslot']) < 8 && (!isset($row['ramslotcount']) || $row['ramslotcount'] <= 8))) {
						$row['ramslot'][]['size'] = '_____';
					}
				}
				if (preg_match('/^\s*Form Factor:\s*(.*?)\s*$/i', $line, $out) && $out[1] !== 'Unknown') {
					$ramForm = $out[1];
				}
				if (preg_match('/^\s*Type:\s*(.*?)\s*$/i', $line, $out) && $out[1] !== 'Unknown') {
					$ramType = $out[1];
				}
				if (preg_match('/^\s*Speed:\s*(\d.*?)\s*$/i', $line, $out)) {
					$ramSpeed = $out[1];
				}
				if (preg_match('/^\s*Configured Clock Speed:\s*(\d.*?)\s*$/i', $line, $out)) {
					$ramClockSpeed = $out[1];
				}
			} elseif ($section === 'BIOS Information') {
				if (preg_match(',^\s*Release Date:\s*(\d{2}/\d{2}/\d{4})\s*$,i', $line, $out)) {
					$row['biosdate'] = date('d.m.Y', strtotime($out[1]));
				} elseif (preg_match('/^\s*BIOS Revision:\s*(.*?)\s*$/i', $line, $out)) {
					$row['biosrevision'] = $out[1];
				} elseif (preg_match('/^\s*Version:\s*(.*?)\s*$/i', $line, $out)) {
					$row['biosversion'] = $out[1];
				}
			}
		}
		if (empty($row['ramslotcount'])) {
			$row['ramslotcount'] = isset($row['ramslot']) ? count($row['ramslot']) : 0;
		}
	}

	public static function parseHdd(&$row, $data)
	{
		$hdds = array();
		// Could have more than one disk - linear scan
		$lines = preg_split("/[\r\n]+/", $data);
		$i = 0;
		$mbrToMbFactor = $sectorToMbFactor = 0;
		foreach ($lines as $line) {
			if (preg_match('/^Disk (\S+):.* (\d+) bytes/i', $line, $out)) {
				// --- Beginning of MBR disk ---
				unset($hdd);
				if ($out[2] < 10000) // sometimes vmware reports lots of 512byte disks
					continue;
				if (substr($out[1], 0, 8) === '/dev/dm-') // Ignore device mapper
					continue;
				// disk total size and name
				$mbrToMbFactor = 0; // This is != 0 for mbr
				$sectorToMbFactor = 0; // This is != for gpt
				$hdd = array(
					'devid' => 'devid-' . ++$i,
					'dev' => $out[1],
					'sectors' => 0,
					'size' => round($out[2] / (1024 * 1024 * 1024)),
					'used' => 0,
					'partitions' => array(),
					'json' => array(),
				);
				$hdds[] = &$hdd;
			} elseif (preg_match('/^Disk (\S+):\s+(\d+)\s+sectors,/i', $line, $out)) {
				// --- Beginning of GPT disk ---
				unset($hdd);
				if ($out[2] < 1000) // sometimes vmware reports lots of 512byte disks
					continue;
				if (substr($out[1], 0, 8) === '/dev/dm-') // Ignore device mapper
					continue;
				// disk total size and name
				$mbrToMbFactor = 0; // This is != 0 for mbr
				$sectorToMbFactor = 0; // This is != for gpt
				$hdd = array(
					'devid' => 'devid-' . ++$i,
					'dev' => $out[1],
					'sectors' => $out[2],
					'size' => 0,
					'used' => 0,
					'partitions' => array(),
					'json' => array(),
				);
				$hdds[] = &$hdd;
			} elseif (preg_match('/^Units =.*= (\d+) bytes/i', $line, $out)) {
				// --- MBR: Line that tells us how to interpret units for the partition lines ---
				// Unit for start and end
				$mbrToMbFactor = $out[1] / (1024 * 1024); // Convert so that multiplying by unit yields MiB
			} elseif (preg_match('/^Logical sector size:\s*(\d+)/i', $line, $out)) {
				// --- GPT: Line that tells us the logical sector size used everywhere ---
				$sectorToMbFactor = $out[1] / (1024 * 1024);
			} elseif (isset($hdd) && $mbrToMbFactor !== 0 && preg_match(',^/dev/(\S+)\s+.*\s(\d+)[\+\-]?\s+(\d+)[\+\-]?\s+\d+[\+\-]?\s+([0-9a-f]+)\s+(.*)$,i', $line, $out)) {
				// --- MBR: Partition entry ---
				// Some partition
				$type = strtolower($out[4]);
				if ($type === '5' || $type === 'f' || $type === '85') {
					continue;
				} elseif ($type === '44') {
					$out[5] = 'OpenSLX-ID44';
					$color = '#5c1';
				} elseif ($type === '45') {
					$out[5] = 'OpenSLX-ID45';
					$color = '#0d7';
				} elseif ($type === '82') {
					$color = '#48f';
				} else {
					$color = '#e55';
				}

				$partsize = round(($out[3] - $out[2]) * $mbrToMbFactor);
				$hdd['partitions'][] = array(
					'id' => $out[1],
					'name' => $out[1],
					'size' => round($partsize / 1024, $partsize < 1024 ? 1 : 0),
					'type' => $out[5],
				);
				$hdd['json'][] = array(
					'label' => $out[1],
					'value' => $partsize,
					'color' => $color,
				);
				$hdd['used'] += $partsize;
			} elseif (isset($hdd) && $sectorToMbFactor !== 0 && preg_match(',^\s*(\d+)\s+(\d+)[\+\-]?\s+(\d+)[\+\-]?\s+\S+\s+([0-9a-f]+)\s+(.*)$,i', $line, $out)) {
				// --- GPT: Partition entry ---
				// Some partition
				$type = $out[5];
				if ($type === 'OpenSLX-ID44') {
					$color = '#5c1';
				} elseif ($type === 'OpenSLX-ID45') {
					$color = '#0d7';
				} elseif ($type === 'Linux swap') {
					$color = '#48f';
				} else {
					$color = '#e55';
				}
				$id = $hdd['devid'] . '-' . $out[1];
				$partsize = round(($out[3] - $out[2]) * $sectorToMbFactor);
				$hdd['partitions'][] = array(
					'id' => $id,
					'name' => $out[1],
					'size' => round($partsize / 1024, $partsize < 1024 ? 1 : 0),
					'type' => $type,
				);
				$hdd['json'][] = array(
					'label' => $id,
					'value' => $partsize,
					'color' => $color,
				);
				$hdd['used'] += $partsize;
			}
		}
		unset($hdd);
		$i = 0;
		foreach ($hdds as &$hdd) {
			$hdd['used'] = round($hdd['used'] / 1024);
			if ($hdd['size'] === 0 && $hdd['sectors'] !== 0) {
				$hdd['size'] = round(($hdd['sectors'] * $sectorToMbFactor) / 1024);
			}
			$free = $hdd['size'] - $hdd['used'];
			if ($hdd['size'] > 0 && ($free > 5 || ($free / $hdd['size']) > 0.1)) {
				$hdd['partitions'][] = array(
					'id' => 'free-id-' . $i,
					'name' => Dictionary::translate('unused'),
					'size' => $free,
					'type' => '-',
				);
				$hdd['json'][] = array(
					'label' => 'free-id-' . $i,
					'value' => $free * 1024,
					'color' => '#aaa',
				);
				++$i;
			}
			$hdd['json'] = json_encode($hdd['json']);
		}
		unset($hdd);
		$row['hdds'] = &$hdds;
	}

	public static function parsePci(&$pci1, &$pci2, $data)
	{
		preg_match_all('/[a-f0-9\:\.]{7}\s+"(Class\s*)?(?<class>[a-f0-9]{4})"\s+"(?<ven>[a-f0-9]{4})"\s+"(?<dev>[a-f0-9]{4})"/is', $data, $out, PREG_SET_ORDER);
		$NOW = time();
		$pci = array();
		foreach ($out as $entry) {
			if (!isset($pci[$entry['class']])) {
				$class = 'c.' . $entry['class'];
				$res = Page_Statistics::getPciId('CLASS', $class);
				if ($res === false || $res['dateline'] < $NOW) {
					$pci[$entry['class']]['lookupClass'] = 'do-lookup';
					$pci[$entry['class']]['class'] = $class;
				} else {
					$pci[$entry['class']]['class'] = $res['value'];
				}
			}
			$new = array(
				'ven' => $entry['ven'],
				'dev' => $entry['ven'] . ':' . $entry['dev'],
			);
			$res = Page_Statistics::getPciId('VENDOR', $new['ven']);
			if ($res === false || $res['dateline'] < $NOW) {
				$new['lookupVen'] = 'do-lookup';
			} else {
				$new['ven'] = $res['value'];
			}
			$res = Page_Statistics::getPciId('DEVICE', $new['dev']);
			if ($res === false || $res['dateline'] < $NOW) {
				$new['lookupDev'] = 'do-lookup';
			} else {
				$new['dev'] = $res['value'] . ' (' . $new['dev'] . ')';
			}
			$pci[$entry['class']]['entries'][] = $new;
		}
		ksort($pci);
		foreach ($pci as $class => $entry) {
			if ($class === '0300' || $class === '0200' || $class === '0403') {
				$pci1[] = $entry;
			} else {
				$pci2[] = $entry;
			}
		}
	}

	public static function parseSmartctl(&$hdds, $data)
	{
		$lines = preg_split("/[\r\n]+/", $data);
		foreach ($lines as $line) {
			if (preg_match('/^NEXTHDD=(.+)$/', $line, $out)) {
				unset($dev);
				foreach ($hdds as &$hdd) {
					if ($hdd['dev'] === $out[1]) {
						$dev = &$hdd;
					}
				}
				continue;
			}
			if (!isset($dev)) {
				continue;
			}
			if (preg_match('/^([A-Z][^:]+):\s*(.*)$/', $line, $out)) {
				$dev['s_' . preg_replace('/\s|-|_/', '', $out[1])] = $out[2];
			} elseif (preg_match('/^\s*\d+\s+(\S+)\s+\S+\s+\d+\s+\d+\s+\S+\s+\S+\s+(\d+)(\s|$)/', $line, $out)) {
				$dev['s_' . preg_replace('/\s|-|_/', '', $out[1])] = $out[2];
			}
		}
		// Format strings
		foreach ($hdds as &$hdd) {
			if (isset($hdd['s_PowerOnHours'])) {
				$hdd['PowerOnTime'] = '';
				$val = (int)$hdd['s_PowerOnHours'];
				if ($val > 8760) {
					$hdd['PowerOnTime'] .= floor($val / 8760) . 'Y, ';
					$val %= 8760;
				}
				if ($val > 720) {
					$hdd['PowerOnTime'] .= floor($val / 720) . 'M, ';
					$val %= 720;
				}
				if ($val > 24) {
					$hdd['PowerOnTime'] .= floor($val / 24) . 'd, ';
					$val %= 24;
				}
				$hdd['PowerOnTime'] .= $val . 'h';
			}
		}
	}

}
