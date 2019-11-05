<?php

class LocationHooks
{

	/**
	 * Resolve baseconfig id to locationid -- noop in this case
	 */
	public static function baseconfigLocationResolver($id)
	{
		return $id;
	}

	/**
	 * Hook to get inheritance tree for all config vars
	 * @param int $id Locationid currently being edited
	 */
	public static function baseconfigInheritance($id)
	{
		$locs = Location::getLocationsAssoc();
		if ($locs === false || !isset($locs[$id]))
			return [];
		BaseConfig::prepareWithOverrides([
			'locationid' => $locs[$id]['parentlocationid']
		]);
		return ConfigHolder::getRecursiveConfig(true);
	}

}