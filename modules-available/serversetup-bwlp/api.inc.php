<?php

// Menu mode

// Check if required arguments are given; if not, spit out according script and chain to self
$uuid = Request::any('uuid', false, 'string');
// Get platform - EFI or PCBIOS
$platform = Request::any('platform', false, 'string');
$manuf = Request::any('manuf', false, 'string');
$product = Request::any('product', false, 'string');

if ($platform === false || ($uuid === false && $product === false)) {
	$url = parse_url($_SERVER['REQUEST_URI']);
	$urlbase = $url['path'];
	if (empty($url['query'])) {
		$arr = [];
	} else {
		parse_str($url['query'], $arr);
		$arr = array_map('urlencode', $arr);
	}
	$arr['uuid'] = '${uuid:uristring}';
	$arr['mac'] = '${mac}';
	$arr['manuf'] = '${manufacturer:uristring}';
	$arr['product'] = '${product:uristring}';
	$arr['platform'] = '${platform:uristring}';
	$query = '?';
	foreach ($arr as $k => $v) {
		$query .= $k . '=' . $v . '&';
	}
	$query = substr($query, 0, -1);
	echo <<<HERE
#!ipxe
:retry
chain -ar $urlbase$query ||
echo Chaining to self failed with \${errno}, retrying in a bit...
sleep 5
goto retry
HERE;
	exit;
}
$platform = strtoupper($platform);

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
$menu = IPxeMenu::forClient($ip, $uuid);


// Get preferred localboot method, depending on system model
$localboot = false;
$model = false;
if ($uuid !== false && Module::get('statistics') !== false) {
	// If we have the machine table, we rather try to look up the system model from there, using the UUID
	$row = Database::queryFirst('SELECT systemmodel FROM machine WHERE machineuuid = :uuid', ['uuid' => $uuid]);
	if ($row !== false && !empty($row['systemmodel'])) {
		$model = $row['systemmodel'];
	}
}
if ($model === false) {
	// Otherwise use what iPXE sent us
	function modfilt($str)
	{
		if (empty($str) || preg_match('/product\s+name|be\s+filled|unknown|default\s+string/i', $str))
			return false;
		return trim(preg_replace('/\s+/', ' ', $str));
	}
	$manuf = modfilt($manuf);
	$product = modfilt($product);
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

// TODO: Feature check for our own iPXE extensions, stay compatible to stock iPXE

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

set ipappend1 ip=\${ip}:{$serverIp}:\${gateway}:\${netmask}
set ipappend2 BOOTIF=01-\${mac:hexhyp}
set serverip $serverIp ||

# Clean up in case we've been chained to
imgfree ||

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

$output .= $menu->getMenuDefinition('target', $platform);

$output .= <<<HERE

console --left 60 --top 130 --right 67 --bottom 86 --quick ||
goto \${target} ||
echo Could not find menu entry in script.
prompt Press any key to continue.
goto start

HERE;

$output .= $menu->getItemsCode($platform);

// TODO: Work out memtest stuff. Needs to be put on server (install/update script) -- PCBIOS only? Chain EFI -> BIOS?

/*

:i5
chain -a /tftp/memtest.0 passes=1 onepass || goto membad
prompt Memory OK. Press a key.
goto init

:i8
set x:int32 0
:again
console --left 60 --top 130 --right 67 --bottom 96 --picture bg-load --keep --quick ||
console --left 55 --top 88 --right 63 --bottom 64 --picture bg-menu --keep --quick ||
inc x
iseq \${x} 20 || goto again
prompt DONE. Press dein Knie.
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
