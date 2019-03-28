<?php

class Localboot
{

	const PROPERTY_KEY = 'serversetup.localboot';

	const BOOT_METHODS = [
		'PCBIOS' => [
			'EXIT' => 'exit 1',
			'COMBOOT' => 'set netX/209:string localboot.cfg ||
set netX/210:string http://${serverip}/tftp/sl-bios/ ||
chain -ar /tftp/sl-bios/lpxelinux.0',
			'SANBOOT' => 'sanboot --no-describe',
		],
		'EFI' => [
			'EXIT' => 'exit 1',
			'COMBOOT' => 'set netX/209:string localboot.cfg ||
set netX/210:string http://${serverip}/tftp/sl-efi64/ ||
chain -ar /tftp/sl-efi64/syslinux.efi',
		],
	];

	public static function getDefault()
	{
		$ret = explode(',', Property::get(self::PROPERTY_KEY, 'SANBOOT,EXIT'));
		if (empty($ret)) {
			$ret = ['SANBOOT', 'EXIT'];
		} elseif (count($ret) < 2) {
			$ret[] = 'EXIT';
		}
		if (null === self::BOOT_METHODS['PCBIOS'][$ret[0]]) {
			$ret[0] = 'SANBOOT';
		}
		if (null === self::BOOT_METHODS['EFI'][$ret[1]]) {
			$ret[1] = 'EXIT';
		}
		return ['PCBIOS' => $ret[0], 'EFI' => $ret[1]];
	}

	public static function setDefault($pcbios, $efi)
	{
		Property::set(self::PROPERTY_KEY, "$pcbios,$efi");
	}

}
