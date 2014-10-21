<?php

/**
 * This class is supposed to fill the database with default entries (where required).
 * The insertion should be non-destructive, so if an entry already exists (and
 * possibly changed), it should be skipped.
 */
class DefaultData
{

	public static function populate()
	{
		self::addSettingCategories();
		self::addSettings();
	}

	/**
	 * Categories for basic system config / config variables
	 */
	private static function addSettingCategories()
	{
		$cats = array(
			1 => 30, // Inactivity/Shutdown
			2 => 20, // Internet access
			3 => 100, // Timesync
			4 => 10, // System config
			5 => 15, // Public Shared folder
			6 => 20000, // Unassigned/no category
		);
		foreach ($cats as $cat => $sort) {
			Database::exec("INSERT IGNORE INTO cat_setting (catid, sortval) VALUES (:catid, :sortval)", array(
				'catid' => $cat,
				'sortval' => $sort
			));
		}
	}

	/**
	 * Settings for basic system config
	 */
	private static function addSettings()
	{
		$data = array(
			array(
				'setting' => 'SLX_ADDONS',
				'catid' => '6',
				'defaultvalue' => 'vmware',
				'permissions' => '2',
				'validator' => ''
			),
			array(
				'setting' => 'SLX_BIOS_CLOCK',
				'catid' => '3',
				'defaultvalue' => 'off',
				'permissions' => '2',
				'validator' => 'list:off|local|utc'
			),
			array(
				'setting' => 'SLX_LOGOUT_TIMEOUT',
				'catid' => '1',
				'defaultvalue' => '1800',
				'permissions' => '2',
				'validator' => 'regex:/^\d*$/'
			),
			array(
				'setting' => 'SLX_NET_DOMAIN',
				'catid' => '2',
				'defaultvalue' => '',
				'permissions' => '2',
				'validator' => ''
			),
			array(
				'setting' => 'SLX_NTP_SERVER',
				'catid' => '3',
				'defaultvalue' => '0.de.pool.ntp.org 1.de.pool.ntp.org',
				'permissions' => '2',
				'validator' => ''
			),
			array(
				'setting' => 'SLX_PROXY_BLACKLIST',
				'catid' => '2',
				'defaultvalue' => '',
				'permissions' => '2',
				'validator' => ''
			),
			array(
				'setting' => 'SLX_PROXY_IP',
				'catid' => '2',
				'defaultvalue' => '',
				'permissions' => '2',
				'validator' => ''
			),
			array(
				'setting' => 'SLX_PROXY_MODE',
				'catid' => '2',
				'defaultvalue' => 'off',
				'permissions' => '2',
				'validator' => 'list:off|on|auto'
			),
			array(
				'setting' => 'SLX_PROXY_PORT',
				'catid' => '2',
				'defaultvalue' => '',
				'permissions' => '2',
				'validator' => 'regex:/^\d*$/'
			),
			array(
				'setting' => 'SLX_PROXY_TYPE',
				'catid' => '2',
				'defaultvalue' => 'socks5',
				'permissions' => '2',
				'validator' => ''
			),
			array(
				'setting' => 'SLX_REMOTE_LOG_SESSIONS',
				'catid' => '6',
				'defaultvalue' => 'anonymous',
				'permissions' => '2',
				'validator' => 'list:yes|anonymous|no'
			),
			array(
				'setting' => 'SLX_ROOT_PASS',
				'catid' => '4',
				'defaultvalue' => '',
				'permissions' => '2',
				'validator' => 'function:linuxPassword'
			),
			array(
				'setting' => 'SLX_SHUTDOWN_SCHEDULE',
				'catid' => '1',
				'defaultvalue' => '22:10 00:00',
				'permissions' => '2',
				'validator' => 'regex:/^(\s*\d{1,2}:\d{1,2})*\s*$/'
			),
			array(
				'setting' => 'SLX_SHUTDOWN_TIMEOUT',
				'catid' => '1',
				'defaultvalue' => '1200',
				'permissions' => '2',
				'validator' => 'regex:/^\d*$/'
			),
			array(
				'setting' => 'SLX_COMMON_SHARE_PATH',
				'catid' => '5',
				'defaultvalue' => '',
				'permissions' => '2',
				'validator' => 'function:networkShare'
			),
			array(
				'setting' => 'SLX_COMMON_SHARE_AUTH',
				'catid' => '5',
				'defaultvalue' => 'guest',
				'permissions' => '2',
				'validator' => 'list:guest|user'
			),
			array(
				'setting' => 'SLX_BENCHMARK_VM',
				'catid' => '6',
				'defaultvalue' => '',
				'permissions' => '2',
				'validator' => ''
			),
		);
		foreach ($data as $entry) {
			Database::exec("INSERT IGNORE INTO setting (setting, catid, defaultvalue, permissions, validator)"
				. "VALUES (:setting, :catid, :defaultvalue, :permissions, :validator)", $entry);
		}
	}

}
