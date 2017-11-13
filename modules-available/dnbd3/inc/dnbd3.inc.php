<?php

class Dnbd3 {

	const PROP_ENABLED = 'dnbd3.enabled';
	const PROP_NFS_FALLBACK = 'dnbd3.nfs-fallback';

	public static function isEnabled()
	{
		return Property::get(self::PROP_ENABLED, 0) ? true : false;
	}

	public static function setEnabled($bool)
	{
		Property::set(self::PROP_ENABLED, $bool ? 1 : 0);
		$task = Taskmanager::submit('Systemctl', array(
			'operation' => ($bool ? 'start' : 'stop'),
			'service' => 'dnbd3-server'
		));
		return $task;
	}

	public static function hasNfsFallback()
	{
		return Property::get(self::PROP_NFS_FALLBACK, 0) ? true : false;
	}

	public static function setNfsFallback($bool)
	{
		Property::set(self::PROP_NFS_FALLBACK, $bool ? 1 : 0);
	}

	public static function getLocalStatus()
	{

	}

}