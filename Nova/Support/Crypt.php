<?php
namespace Nova\Support;

use Random\RandomException;

class Crypt
{
	const SALT   = 'c1dd3124c7776282914ecafcc71dfe07';
	const CIPHER = 'AES-256-CBC';

	public static function encrypt(string $text, string $salt = '', string $cipher = ''): string
	{
		$cipher = self::cipher($cipher);
		$iv     = self::iv($cipher);

		$hash = $iv . openssl_encrypt($text, $cipher, self::salt($salt), OPENSSL_RAW_DATA, $iv);

		return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
	}

	public static function decrypt(string $hash, string $salt = '', string $cipher = ''): string
	{
		$cipher = self::cipher($cipher);

		$hash = base64_decode(str_pad(strtr($hash, '-_', '+/'), strlen($hash) % 4, '='));

		$iv_length = openssl_cipher_iv_length($cipher);
		$iv        = substr($hash, 0, $iv_length);
		$hash      = substr($hash, $iv_length);

		return openssl_decrypt($hash, $cipher, self::salt($salt), OPENSSL_RAW_DATA, $iv);
	}

	public static function hash(string $text, string $cipher = 'sha256'): string
	{
		return hash($cipher, $text, false);
	}

	public static function salt(string $salt = '')
	{
		return $salt ?: conf('app.key') ?: static::SALT;
	}

	public static function cipher(string $cipher = ''): string
	{
		return $cipher ?: conf('app.cipher') ?: static::CIPHER;
	}

	protected static function iv($cipher): string
	{
		$iv_length = openssl_cipher_iv_length($cipher);

		try {
			return random_bytes($iv_length);
		} catch (RandomException) {
			return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyz'), 0, $iv_length);
		}
	}
}
