<?php

class Dnbd3 {

	const PROP_ENABLED = 'dnbd3.enabled';

	public static function isEnabled()
	{
		return Property::get(self::PROP_ENABLED, 0) ? true : false;
	}

	public static function setEnabled($bool)
	{
		Property::set(self::PROP_ENABLED, $bool ? 1 : 0);
	}

}