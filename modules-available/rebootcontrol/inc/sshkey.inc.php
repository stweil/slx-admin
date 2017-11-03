<?php

class SSHKey
{

	public static function getPrivateKey(&$regen = false) {
		$privKey = Property::get("rebootcontrol-private-key");
		if (!$privKey) {
			$rsaKey = openssl_pkey_new(array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA));
			openssl_pkey_export( openssl_pkey_get_private($rsaKey), $privKey);
			Property::set("rebootcontrol-private-key", $privKey);
			if (Module::isAvailable('sysconfig')) {
				ConfigTgz::rebuildAllConfigs();
			}
			$regen = true;
		}
		return $privKey;
	}

	public static function getPublicKey() {
		$pkImport = openssl_pkey_get_private(self::getPrivateKey());
		return self::sshEncodePublicKey($pkImport);
	}

	private static function sshEncodePublicKey($privKey) {
		$keyInfo = openssl_pkey_get_details($privKey);
		$buffer  = pack("N", 7) . "ssh-rsa" .
			self::sshEncodeBuffer($keyInfo['rsa']['e']) .
			self::sshEncodeBuffer($keyInfo['rsa']['n']);
		return "ssh-rsa " . base64_encode($buffer);
	}

	private static function sshEncodeBuffer($buffer) {
		$len = strlen($buffer);
		if (ord($buffer[0]) & 0x80) {
			$len++;
			$buffer = "\x00" . $buffer;
		}
		return pack("Na*", $len, $buffer);
	}

}