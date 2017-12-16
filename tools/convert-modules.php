<?php

error_reporting(E_ALL);

$tags = array();
foreach (glob('modules/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
	if (preg_match('#.*/(\w+)/?$#', $dir, $out)) {
		$tags[] = $out[1];
	}
}

foreach (glob('lang/??/messages-hardcoded.json', GLOB_NOSORT) as $dir) {
	$data = json_decode(file_get_contents($dir), true);
	if (!is_array($data)) {
		echo "Kackfile $dir\n";
		continue;
	}
	echo "Handling $dir\n";
	$lang = substr($dir, 5, 2);
	foreach ($tags as $mod) {
		$tag = 'lang_' . $mod;
		if (isset($data[$tag])) {
			@mkdir('modules/' . $mod . '/lang/' . $lang, 0755, true);
			$destFile = 'modules/' . $mod . '/lang/' . $lang . '/module.json';
			$dest = json_decode(file_get_contents($destFile), true);
			if (!is_array($dest)) {
				$dest = array();
			}
			$dest['module_name'] = $data[$tag];
			unset($data[$tag]);
			ksort($dest);
			ksort($data);
			file_put_contents($destFile, json_encode($dest, JSON_PRETTY_PRINT)) > 0 && file_put_contents($dir, json_encode($data, JSON_PRETTY_PRINT));
		}
	}
}

foreach (glob('lang/??/modules/*.json', GLOB_NOSORT) as $path) {
	if (!preg_match('#lang/(..)/modules/(\w+)\.json#', $path, $out)) continue;
	$data = json_decode(file_get_contents($path), true);
	if (!is_array($data)) {
		echo "Kackfile $path\n";
		continue;
	}
	echo "Handling $path\n";
	$lang = $out[1];
	$mod = $out[2];
	if (!in_array($mod, $tags)) continue;
	@mkdir('modules/' . $mod . '/lang/' . $lang, 0755, true);
	$destFile = 'modules/' . $mod . '/lang/' . $lang . '/module.json';
	$dest = json_decode(file_get_contents($destFile), true);
	if (!is_array($dest)) {
		$dest = array();
	}
	foreach (array_keys($data) as $k) {
		if (substr($k, 0, 5) !== 'lang_') continue;
		if (empty($dest[$k]) && !empty($data[$k])) $dest[$k] = $data[$k];
		unset($data[$k]);
	}
	ksort($dest);
	ksort($data);
	file_put_contents($destFile, json_encode($dest, JSON_PRETTY_PRINT)) > 0 && file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

foreach (
		glob('modules/*/lang/??/templates/*.json', GLOB_NOSORT)
		+ glob('modules/*/lang/??/templates/*/*.json', GLOB_NOSORT)
		+ glob('modules/*/lang/??/templates/*/*/*.json', GLOB_NOSORT)
		as $path) {
	if (!preg_match('#modules/([^/]+)/lang/(..)/temp#', $path, $out)) continue;
	$module = $out[1];
	$lang = $out[2];
	$old = @json_decode(@file_get_contents($path), true);
	if (!is_array($old) || empty($old)) {
		unlink($path);
		continue;
	}
	$exFile = "modules/$module/lang/$lang/template-tags.json";
	$existing = @json_decode(@file_get_contents($exFile), true);
	if (!is_array($existing)) $existing = array();
	$existing = $existing + $old;
	ksort($existing);
	if (file_put_contents($exFile, json_encode($existing, JSON_PRETTY_PRINT)) > 0) {
		unlink($path);
	}
}

echo "LAST SECTION\n";

foreach (glob('modules/*/lang/??/module.json', GLOB_NOSORT) as $path) {
	if (!preg_match('#modules/([^/]+)/lang/(..)/module.json#', $path, $out)) continue;
	if (!is_array($data)) {
		echo "Kackfile $path\n";
		continue;
	}
	$old = json_decode(file_get_contents($path), true);
	if (!is_array($old) || empty($old)) {
		echo "Is empty\n";
		unlink($path);
		continue;
	}
	echo "Handling $path\n";
	$module = $out[1];
	$lang = $out[2];
	$exFile = "modules/$module/lang/$lang/template-tags.json";
	$existing = @json_decode(@file_get_contents($exFile), true);
	if (!is_array($existing)) $existing = array();
	foreach (array_keys($old) as $k) {
		if (substr($k, 0, 5) === 'lang_') {
			if (empty($existing[$k])) {
				$existing[$k] = $old[$k];
			}
			unset($old[$k]);
		}
	}
	ksort($existing);
	ksort($old);
	if (file_put_contents($exFile, json_encode($existing, JSON_PRETTY_PRINT)) > 0) {
		if (empty($old)) {
			echo "Old file deleted\n";
			unlink($path);
		} else {
			echo "Old file shortened\n";
			file_put_contents($path, json_encode($old, JSON_PRETTY_PRINT));
		}
	}
}


echo "Fixing up messages...\n";

	function getAllFiles($dir, $extension)
	{
		$php = array();
		$extLen = -strlen($extension);
		foreach (scandir($dir, SCANDIR_SORT_NONE) as $name) {
			if ($name === '.' || $name === '..')
				continue;
			$name = $dir . '/' . $name;
			if (substr($name, $extLen) === $extension && is_file($name)) {
				$php[] = $name;
			} else if (is_dir($name)) {
				$php = array_merge($php, getAllFiles($name, $extension));
			}
		}
		return $php;
	}

$files = array_merge(getAllFiles('modules-available', '.php'), getAllFiles('inc', '.php'));
$files[] = 'index.php';
$messages = array();

foreach ($files as $file) {
	$content = file_get_contents($file);
	if ($content === false)
		continue;
	if (preg_match_all('/Message\s*::\s*add\w+\s*\(\s*[\'"](?<tag>[^\'"\.]*)[\'"]\s*[\)\,]/i', $content, $out, PREG_SET_ORDER) < 1)
		continue;
	foreach ($out as $set) {
		if (preg_match('#modules-available/([^/]+)/#', $file, $a)) {
			$id = $a[1];
		} else {
			$id = 'main';
		}
		$messages[$set['tag']][$id]++;
	}
}

$langs = array();
foreach (glob('lang/??/messages.json', GLOB_NOSORT) as $path) {
	$lang = substr($path, 5, 2);
	if ($lang === '..') continue;
	$data = json_decode(file_get_contents($path), true);
	if (is_array($data)) {
		$langs[$lang] = $data;
		echo "Have $lang\n";
	}
}

echo "Processing\n";
foreach ($messages as $id => $modules) {
	asort($modules, SORT_NUMERIC);
	$modules = array_reverse($modules, true);
	reset($modules);
	$topModule = key($modules);
	$topCount = $modules[$topModule];
	$sum = 0;
	foreach ($modules as $c) {
		$sum += $c;
	}
	$fac = $topCount / $sum;
	echo "****************** $id\n";
	print_r($modules);
	if (count($modules) > 3 || isset($modules['main'])) {
		$destMods = array('main');
	} elseif (count($modules) === 1) {
		$destMods = array($topModule);
	} else {
		$destMods = array_keys($modules);
	}
	foreach ($langs as $lang => &$source) {
		if (!isset($source[$id]))
			continue;
		$del = true;
		foreach ($destMods as $destMod) {
			$dest = json_decode(file_get_contents('modules/' . $destMod . '/lang/' . $lang . '/messages.json'), true);
			if (!is_array($dest)) $dest = array();
			if (!empty($dest[$id]))
				continue;
			$dest[$id] = $source[$id];
			mkdir('modules/' . $destMod . '/lang/' . $lang, 0775, true);
			if (file_put_contents('modules/' . $destMod . '/lang/' . $lang . '/messages.json', json_encode($dest, JSON_PRETTY_PRINT)) < 1) {
				$del = false;
			}
		}
		if ($del) {
			unset($source[$id]);
		}
	}
	unset($source);
}

foreach ($langs as $lang => $source) {
	file_put_contents('lang/' . $lang . '/messages.json', json_encode($source, JSON_PRETTY_PRINT));
}

