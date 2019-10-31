<?php

class LocationHooks
{

	/**
	 * Used for baseconfig hook
	 * @param $locationId
	 * @return bool|array ('value' => x, 'display' => y), false if no parent or unknown id
	 */
	public static function getBaseconfigParent($locationId)
	{
		$locationId = (int)$locationId;
		$assoc = Location::getLocationsAssoc();
		if (!isset($assoc[$locationId]))
			return false;
		$locationId = (int)$assoc[$locationId]['parentlocationid'];
		if (!isset($assoc[$locationId]))
			return false;
		return array('value' => $locationId, 'display' => $assoc[$locationId]['locationname']);
	}

	/**
	 * Resolve baseconfig id to locationid -- noop in this case
	 */
	public static function baseconfigLocationResolver($id)
	{
		return $id;
	}

}