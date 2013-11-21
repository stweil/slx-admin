<?php

class Crypto
{

	/**
	 * Hash given string using crypt's $6$,
	 * which translates to ~130 bit salt
	 * and 5000 rounds of hashing with SHA-512.
	 */
	public static function hash6($password)
	{
		$salt = substr(str_replace('+', '.', base64_encode(pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()))), 0, 16);
		$hash = crypt($password, '$6$' . $salt);
		if (strlen($hash) < 60) Util::traceError('Error hashing password using SHA-512');
		return $hash;
	}

	/**
	 * Check if the given password matches the given cryp hash.
	 * Useful for checking a hashed password.
	 */
	public static function verify($password, $hash)
	{
		return crypt($password, $hash) === $hash;
	}

}

