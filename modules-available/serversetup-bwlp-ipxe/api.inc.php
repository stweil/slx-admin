<?php

// Menu mode

$serverIp = Property::getServerIp();

// Check if required arguments are given; if not, spit out according script and chain to self
$uuid = Request::any('uuid', false, 'string');
// Get platform - EFI or PCBIOS
$platform = Request::any('platform', false, 'string');
$manuf = Request::any('manuf', false, 'string');
$product = Request::any('product', false, 'string');
$slxExtensions = Request::any('slx-extensions', false, 'int');

if ($platform === false || ($uuid === false && $product === false) || $slxExtensions === false) {
	// Redirect to self with added parameters
	$url = parse_url($_SERVER['REQUEST_URI']);
	if (isset($_SERVER['SCRIPT_URI']) && preg_match('#^(\w+://[^/]+)#', $_SERVER['SCRIPT_URI'], $out)) {
		$urlbase = $out[1];
	} elseif (isset($_SERVER['REQUEST_SCHEME']) && isset($_SERVER['SERVER_NAME'])) {
		$urlbase = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_NAME'];
	} elseif (isset($_SERVER['REQUEST_SCHEME']) && isset($_SERVER['SERVER_ADDR'])) {
		$urlbase = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_ADDR'];
	} else {
		$urlbase = 'http://' . $serverIp;
	}
	$urlbase .= $url['path'];
	if (empty($url['query'])) {
		$arr = [];
	} else {
		parse_str($url['query'], $arr);
		foreach ($arr as &$v) {
			$v = urlencode($v);
		}
		unset($v);
	}
	$arr['uuid'] = '${uuid}';
	$arr['mac'] = '${mac}';
	$arr['manuf'] = '${manufacturer:uristring}';
	$arr['product'] = '${product:uristring}';
	$arr['platform'] = '${platform:uristring}';
	$query = '?';
	foreach ($arr as $k => $v) {
		$query .= $k . '=' . $v . '&';
	}
	//$query = substr($query, 0, -1);
	echo <<<HERE
#!ipxe
set slxtest:string something ||
iseq \${slxtest:md5} \${} && set slxext 0 || set slxext 1 ||
clear slxtest ||
set self {$urlbase}{$query}slx-extensions=\${slxext}
:retry
echo Chaining to \${self}
chain -ar \${self} ||
echo Chaining to self failed with \${errno}, retrying in a bit...
sleep 5
goto retry
HERE;
	exit;
}
// ipxe has it lowercase, but we use uppercase
$platform = strtoupper($platform);
if ($platform !== 'PCBIOS' && $platform !== 'EFI') {
	$platform = 'PCBIOS'; // Just hope for the best?
}

$BOOT_METHODS = Localboot::BOOT_METHODS[$platform];

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}
$menu = Request::get('menuid', false, 'int');
if ($menu !== false) {
	$menu = new IPxeMenu($menu);
	$initLabel = 'slx_menu';
} else {
	$menu = IPxeMenu::forClient($ip, $uuid);
	$initLabel = 'init';
}
// If this is a menu with a single item, treat a timeout of 0 as "boot immediately" instead of "infinite"
if ($menu->itemCount() === 1 && $menu->timeoutMs() === 0 && ($tmp = $menu->getDefaultScriptLabel()) !== false) {
	$directBoot = "goto $tmp ||";
	$initLabel = 'init';
} else {
	$directBoot = '';
}

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
		if (empty($str) || preg_match('/product\s+name|be\s+filled|unknown|default\s+string|system\s+model|manufacturer/i', $str))
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
	$e = strtolower($platform); // We made sure $platform is either PCBIOS or EFI, so no injection possible
	$row = Database::queryFirst("SELECT $e AS bootmethod FROM serversetup_localboot WHERE systemmodel = :model LIMIT 1",
		['model' => $model]);
	if ($row !== false) {
		$localboot = $row['bootmethod'];
	}
}
if ($localboot === false || !isset($BOOT_METHODS[$localboot])) {
	$localboot = Localboot::getDefault()[$platform];
	if (!isset($BOOT_METHODS[$localboot])) {
		$localboot = array_keys($BOOT_METHODS)[0];
	}
}
// Convert to actual ipxe code
if (isset($BOOT_METHODS[$localboot])) {
	$localboot = $BOOT_METHODS[$localboot];
} else {
	$localboot = 'prompt Localboot not possible';
}

if ($slxExtensions) {
	$slxConsoleUpdate = '--update';
	$slxPasswordOnly = '--nouser';
} else {
	$slxConsoleUpdate = '';
	$slxPasswordOnly = '';
}

$output = <<<HERE
#!ipxe

goto $initLabel || goto fail ||

# functions

# password check with gotos
# set slx_hash to the expected hash
#     slx_salt to the salt to use
#     slx_pw_ok to the label to jump on success
#     slx_pw_fail to label for wrong pw
:slx_pass_check
login $slxPasswordOnly ||
set slxtmp_pw \${password:md5}-\${slx_salt} || goto fail
set slxtmp_pw \${slxtmp_pw:md5} || goto fail
clear password ||
iseq \${slxtmp_pw} \${slx_hash} || prompt Wrong password. Press a key. ||
iseq \${slxtmp_pw} \${slx_hash} || goto \${slx_pw_fail} ||
iseq \${slxtmp_pw} \${slx_hash} && goto \${slx_pw_ok} ||
goto fail

:slx_localboot
imgfree ||
console ||
$localboot || goto fail

# start
:init

set ipappend1 ip=\${ip}:{$serverIp}:\${gateway}:\${netmask}
set ipappend2 BOOTIF=01-\${mac:hexhyp}
set serverip $serverIp ||

# Clean up in case we've been chained to
imgfree ||

$directBoot

imgfetch --name bg-menu /tftp/pxe-menu.png ||

:start

console --left 55 --top 88 --right 63 --bottom 64 --keep --picture bg-menu ||

colour --rgb 0xffffff 7
colour --rgb 0xcccccc 5
cpair --foreground 0 --background 4 1
cpair --foreground 7 --background 5 2
cpair --foreground 7 --background 9 0

:slx_menu

iseq \${serverip} \${} || goto ip_check_ok
goto init
:ip_check_ok

console --left 55 --top 88 --right 63 --bottom 64 $slxConsoleUpdate --keep --picture bg-menu ||

HERE;

$output .= $menu->getMenuDefinition('target', $platform, $slxExtensions);

$output .= <<<HERE

console --left 60 --top 130 --right 67 --bottom 86 $slxConsoleUpdate ||
goto \${target} ||
echo Could not find menu entry in script.
prompt Press any key to continue.
goto start

HERE;

$output .= $menu->getItemsCode($platform);

/*

:i5
chain -a /tftp/memtest.0 passes=1 onepass || goto membad
prompt Memory OK. Press a key.
goto init

:i8
set x:int32 0
:again
console --left 60 --top 130 --right 67 --bottom 96 --picture bg-load --keep ||
console --left 55 --top 88 --right 63 --bottom 64 --picture bg-menu --keep ||
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
