<?php

$ret = IPxe::importLegacyMenu(false);
if ($ret !== false) {
	$num = IPxe::importPxeMenus('/srv/openslx/tftp/pxelinux.cfg');
	if ($num > 0) {
		EventLog::info('Imported old PXELinux menu, with ' . $num . ' additional IP-range based menus.');
	} else {
		EventLog::info('Imported old PXELinux menu.');
	}
}
