<?php

class Localboot
{

	const PROPERTY_KEY = 'serversetup.localboot';

	const BOOT_METHODS = [
		'AUTO' => 'iseq efi ${platform} && exit 1 || sanboot --no-describe',
		'EXIT' => 'exit 1',
		'COMBOOT' => 'chain /tftp/chain.c32 hd0',
		'SANBOOT' => 'sanboot --no-describe',
	];

}