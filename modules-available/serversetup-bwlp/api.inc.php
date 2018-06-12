<?php

$BOOT_METHODS = [
	'EXIT' => 'exit 1',
	'COMBOOT' => 'chain /tftp/chain.c32 hd0',
	'SANBOOT' => 'sanboot --no-describe',
];

$serverIp = Property::getServerIp();

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}
$uuid = Request::any('uuid', false, 'string');
$menu = IPxeMenu::forClient($ip, $uuid);

// Get platform - EFI or PCBIOS
$platform = strtoupper(Request::any('platform', 'PCBIOS', 'string'));

// Get preferred localboot method, depending on system model
$localboot = false;
$model = false;
if ($uuid !== false && Module::get('statistics') !== false) {
	$row = Database::queryFirst('SELECT systemmodel FROM machine WHERE machineuuid = :uuid', ['uuid' => $uuid]);
	if ($row !== false && !empty($row['systemmodel'])) {
		$model = $row['systemmodel'];
	}
}
if ($model === false) {
	function modfilt($str)
	{
		if (empty($str) || preg_match('/product\s+name|be\s+filled|unknown|default\s+string/i', $str))
			return false;
		return trim(preg_replace('/\s+/', ' ', $str));
	}
	$manuf = modfilt(Request::any('manuf', false, 'string'));
	$product = modfilt(Request::any('product', false, 'string'));
	if (!empty($product)) {
		$model = $product;
		if (!empty($manuf)) {
			$model .= " ($manuf)";
		}
	}
}
// Query
if ($model !== false) {
	$row = Database::queryFirst("SELECT bootmethod FROM serversetup_localboot WHERE systemmodel = :model LIMIT 1",
		['model' => $model]);
	if ($row !== false) {
		$localboot = $row['bootmethod'];
	}
}
if ($localboot === false || !isset($BOOT_METHODS[$localboot])) {
	$localboot = Property::get('serversetup.localboot', false);
	if ($localboot === false) {
		if ($platform === 'EFI') {
			// It seems most (all) EFI platforms won't enumerate any drives in ipxe.
			// No idea if this can be fixed in ipxe code in the future.
			$localboot = 'EXIT';
		} else {
			$localboot = 'SANBOOT';
		}
	}
}
if (isset($BOOT_METHODS[$localboot])) {
	// Move preferred method first
	$BOOT_METHODS[] = $BOOT_METHODS[$localboot];
	unset($BOOT_METHODS[$localboot]);
	$BOOT_METHODS = array_reverse($BOOT_METHODS);
}

$output = <<<HERE
#!ipxe

goto init || goto fail ||

# functions

# password check with gotos
# set slx_hash to the expected hash
#     slx_salt to the salt to use
#     slx_pw_ok to the label to jump on success
#     slx_pw_fail to label for wrong pw
:slx_pass_check
login ||
set slxtmp_pw \${password:md5}-\${slx_salt} || goto fail
set slxtmp_pw \${slxtmp_pw:md5} || goto fail
clear password ||
iseq \${slxtmp_pw} \${slx_hash} || prompt Wrong password. Press a key. ||
iseq \${slxtmp_pw} \${slx_hash} || goto \${slx_pw_fail} ||
iseq \${slxtmp_pw} \${slx_hash} && goto \${slx_pw_ok} ||
goto fail

# local boot with either exit 1 or sanboot
:slx_localboot
console ||

HERE;

foreach ($BOOT_METHODS as $line) {
	$output .= "$line || goto fail\n";
}

$output .= <<<HERE
goto fail

# start
:init

iseq \${nic} \${} && set nic 0 ||

set ipappend1 ip=\${net\${nic}/ip}:{$serverIp}:\${net\${nic}/gateway}:\${net\${nic}/netmask}
set ipappend2 BOOTIF=01-\${net\${nic}/mac:hexhyp}
set serverip $serverIp ||

# Clean up in case we've been chained to
imgfree ||

ifopen ||

imgfetch --name bg-load /tftp/openslx.png ||

imgfetch --name bg-menu /tftp/pxe-menu.png ||

:start

console --left 55 --top 88 --right 63 --bottom 64 --keep --picture bg-menu ||

colour --rgb 0xffffff 7
colour --rgb 0xcccccc 5
cpair --foreground 0 --background 4 1
cpair --foreground 7 --background 5 2
cpair --foreground 7 --background 9 0

:slx_menu

console --left 55 --top 88 --right 63 --bottom 64 --quick --keep --picture bg-menu ||

HERE;

$output .= $menu->getMenuDefinition('target');

$output .= <<<HERE

console --left 60 --top 130 --right 67 --bottom 86 --quick ||
goto \${target} ||
echo Could not find menu entry in script.
prompt Press any key to continue.
goto start

HERE;

$output .= $menu->getItemsCode();

/*
:i1
#console ||
echo Welcome to Shell ||
shell
goto slx_menu

:i2
imgfree ||
kernel /boot/default/kernel slxbase=boot/default slxsrv=$serverIp splash BOOTIF=01-\${net\${nic}/mac:hexhyp} || echo Could not download kernel
initrd /boot/default/initramfs-stage31 || echo Could not download initrd
boot -ar || goto fail

:i3
chain -ar \${self} ||
chain -ar /tftp/undionly.kpxe || goto fail

:i4
imgfree ||
sanboot --no-describe --drive 0x80 || goto fail

:i5
chain -a /tftp/memtest.0 passes=1 onepass || goto membad
prompt Memory OK. Press a key.
goto init

:i6
console --left 60 --top 130 --right 67 --bottom 96 --quick --picture bg-load --keep ||
echo Welcome to Shell ||
shell
goto slx_menu

:i7
chain -ar tftp://132.230.4.6/ipxelinux.0 || prompt FAILED PRESS A KEY
goto slx_menu

:i8
set x:int32 0
:again
console --left 60 --top 130 --right 67 --bottom 96 --picture bg-load --keep --quick ||
console --left 55 --top 88 --right 63 --bottom 64 --picture bg-menu --keep --quick ||
inc x
iseq \${x} 20 || goto again
prompt DONE. Press dein Knie.
goto slx_menu

:i9
reboot ||
prompt Reboot failed. Press a key.
goto slx_menu

:i10
poweroff ||
prompt Poweroff failed. Press a key.
goto slx_menu

:membad
iseq \${errno} 0x1 || goto memaborted
params
param scrot \${vram}
imgfetch -a http://132.230.8.113/screen.php##params ||
prompt Memory is bad. Press a key.
goto init

:memaborted
params
param scrot \${vram}
imgfetch -a http://132.230.8.113/screen.php##params ||
prompt Memory test aborted. Press a key.
goto init

*/

$output .= <<<HERE
:fail
prompt Boot failed. Press any key to start.
goto init
HERE;

if ($platform === 'EFI') {
	$cs = 'ASCII';
} else {
	$cs = 'IBM437';
}
Header('Content-Type: text/plain; charset=' . $cs);

echo iconv('UTF-8', $cs . '//TRANSLIT//IGNORE', $output);
