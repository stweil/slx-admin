<?php

class Machine
{
	const NO_DATA = 0;
	const RAW_DATA = 1;

	/**
	 * @var string UUID
	 */
	public $machineuuid;

	/**
	 * @var int|null locationid machine belongs to
	 */
	public $locationid;

	/**
	 * @var string mac address
	 */
	public $macaddr;

	/**
	 * @var string client's ip address
	 */
	public $clientip;

	/**
	 * @var string client's host name
	 */
	public $hostname;

	/**
	 * @var int timestamp of when this machine booted from this server for the first time
	 */
	public $firstseen;

	/**
	 * @var int last time this machine was seen active
	 */
	public $lastseen;

	/**
	 * @var int timestamp of when the machine was booted, 0 if machine is powered off
	 */
	public $lastboot;

	/**
	 * @var int timestamp of when the current user logged in, 0 if machine is idle
	 */
	public $logintime;

	/**
	 * @var string state of machine (OFFLINE, IDLE, OCCUPIED, STANDBY)
	 */
	public $state;

	/**
	 * @var string json data of position inside room (if any), null/empty otherwise
	 */
	public $position;

	/**
	 * @var string|null UUID or name of currently running lecture/session
	 */
	public $currentsession;

	/**
	 * @var string|null name of currently logged in user
	 */
	public $currentuser;

	/**
	 * @var string|null raw data of machine, if requested
	 */
	public $data;

}
