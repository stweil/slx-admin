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
		if (preg_match('/,(OU=.*DC=.*)$/i', Ldap::normalizeDn($binddn), $out)) {
			// Get OU from binddn; works if not given short form of DOMAIN\user or user@domain.fqdn.com
			$searchbase = $out[1];
		} elseif (preg_match('/,(DC=.*)$/i', Ldap::normalizeDn($searchbase), $out)) {
			// Otherwise, shorten search base enough to only consider the DC=..,DC=.. part at the end
			$searchbase = $out[1];
		}
		return $searchbase;
	}

}
