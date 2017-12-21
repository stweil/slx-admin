<?php

class Ldap
{

	public static function normalizeDn($dn)
	{
		return trim(preg_replace('/[,;]\s*/', ',', $dn));
	}

	public static function getSelfSearchBase($binddn, $searchbase)
	{
		// To find ourselves we try to figure out the proper search base, since the given one
		// might be just for users, not for functional or utility accounts
		if (preg_match('/^\w+=[^=]+,(.*)$/i', Ldap::normalizeDn($binddn), $out)) {
			$searchbase = $out[1];
		}
		return $searchbase;
	}

}
