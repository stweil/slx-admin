<?php

require_once('config.php');

@mkdir(CONFIG_SESSION_DIR, 0700);
@chmod(CONFIG_SESSION_DIR, 0700);

session_set_save_handler('sh_open', 'sh_close', 'sh_read', 'sh_write', 'sh_destroy', 'sh_gc');

// Pretty much a reimplementation of the default session handler: Plain files
// Needs to be switched to db later

function sh_open($path, $name)
{
	return true;
}

function sh_close()
{
	return true;
}

function sh_read($id)
{
	return (string)@file_get_contents(CONFIG_SESSION_DIR . '/slx-session-' . $id);
}

function sh_write($id, $data)
{
	return @file_put_contents(CONFIG_SESSION_DIR . '/slx-session-' . $id, $data);
}

function sh_destroy($id)
{
	return @unlink(CONFIG_SESSION_DIR . '/slx-session-' . $id);
}

function sh_gc($maxAgeSeconds)
{
	$files = @glob(CONFIG_SESSION_DIR . '/slx-session-*');
	if (!is_array($files)) return false;
	foreach ($files as $file) {
		if (filemtime($file) + $maxAgeSeconds < time()) {
			@unlink($file);
		}
	}
	return true;
}

