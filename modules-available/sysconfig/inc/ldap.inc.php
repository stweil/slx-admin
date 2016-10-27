<?php

class Ldap
{

	public static function normalizeDn($dn)
	{
		return trim(preg_replace('/[,;]\s*/', ',', $dn));
	}

}
