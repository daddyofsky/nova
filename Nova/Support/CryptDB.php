<?php
namespace Nova\Support;

class CryptDB
{
	protected const DECRYPT_COLUMN_PATTERN = '/(?<=^|[\s(])(?P<column_with_table>(?:\w+\.)?(?P<column>\w+))\s+(?P<operator>[=!<>]+|(?:EQ|NE|[LG]TE?|BETWEEN|(?:NOT )?IN|(?:NOT )?LIKE))(?=[\s(])/i';

	protected static string $cipher       = 'aes-256-cbc';
	protected static string $salt         = '99e0d947e01bbc0a507a1127dc2135b1';
	protected string        $dbname       = '';
	protected array         $cryptConf    = [];
	protected array         $cryptColumns = [];

	public function selectDB($dbname): void
	{
		$this->dbname             = $dbname;
		$this->cryptConf[$dbname] = conf('db.crypt.' . $dbname, []);
		$this->cryptColumns       = [];
	}

	public function table($table): void
	{
		$this->cryptColumns = $this->getCryptColumns($table);
	}

	public function addJoin($table): void
	{
		$this->cryptColumns = array_merge($this->cryptColumns, $this->getCryptColumns($table));
	}

	public static function setCipher(string $cipher): void
	{
		self::$cipher = strtolower($cipher);
	}

	public static function getCipher(): string
	{
		return self::$cipher;
	}

	public static function isCbcCipher(): bool
	{
		return str_contains(self::$cipher, 'cbc');
	}

	public static function setCryptKey(string $salt): void
	{
		self::$salt = $salt;
	}

	public function encryptData(array &$data): void
	{
		if ($this->cryptColumns) {
			$this->cryptColumnCallback($data, $this->cryptColumns, [static::class, 'encrypt']);
		}
	}

	public function decryptData(array &$data): void
	{
		if ($this->cryptColumns) {
			$this->cryptColumnCallback($data, $this->columns, [static::class, 'decrypt']);
		}
	}

	public function applyDecryptColumnQuery(string $key): string
	{
		if (in_array(preg_replace('/^\w+\./', '', $key), $this->cryptColumns, true)) {
			// use decrypt query for encrypted column
			return self::getDecryptColumnQuery($key);
		}

		return preg_replace_callback(self::DECRYPT_COLUMN_PATTERN, [$this, 'applyDecryptColumnQueryCallback'], $key);
	}

	public static function decrypt(string $hash, string $salt = '', string $cipher = ''): string
	{
		if (!$hash || !preg_match('/^x:[0-9a-fA-F]{32,}$/', $hash)) {
			return $hash;
		}

		$cipher = strtolower($cipher ?: self::$cipher);
		$salt   = self::padCryptKey($salt ?: self::$salt, $cipher);

		$org = $hash;

		// hex2bin
		$offset = 2;
		if (PHP_VERSION_ID < 50400) {
			$hash = '';
			$len  = strlen($org);
			for ($i = $offset; $i < $len; $i += 2) {
				$hash .= pack('H*', substr($org, $i, 2));
			}
		} else {
			$hash = hex2bin(substr($hash, $offset));
		}

		if (str_contains($cipher, 'ecb')) {
			// no iv
			// [mysql] AES_DECRYPT(UNHEX(SUBSTRING(column, 3)), 'salt')
			return openssl_decrypt($hash, $cipher, $salt, OPENSSL_RAW_DATA);
		}

		// use iv
		// [mysql] AES_DECRYPT(UNHEX(SUBSTRING(column, 35)), 'salt', UNHEX(SUBSTRING(column, 3, 32)))
		$iv_length = openssl_cipher_iv_length($cipher);
		if (strlen($hash) <= $iv_length) {
			return $org;
		}

		$iv   = substr($hash, 0, $iv_length);
		$hash = substr($hash, $iv_length);
		return openssl_decrypt($hash, $cipher, $salt, OPENSSL_RAW_DATA, $iv);
	}

	public static function encrypt(string $text, string $salt = '', string $cipher = ''): string
	{
		if (!$text || preg_match('/^x:[0-9a-fA-F]{32,}$/', $text)) {
			return $text;
		}

		$cipher = strtolower($cipher ?: self::$cipher);
		$salt   = self::padCryptKey($salt ?: self::$salt, $cipher);

		if (str_contains($cipher, 'ecb')) {
			// no iv
			// [mysql] CONCAT('x:', HEX(AES_ENCRYPT(column, 'salt')))
			return 'x:' . bin2hex(openssl_encrypt($text, $cipher, $salt, OPENSSL_RAW_DATA));
		}

		// use iv
		// [mysql] CONCAT('x:', HEX(CONCAT('iv', AES_ENCRYPT(column, 'salt', 'iv'))))
		$iv_length = openssl_cipher_iv_length($cipher);
		$iv        = random_bytes($iv_length);

		return 'x:' . bin2hex($iv . openssl_encrypt($text, $cipher, $salt, OPENSSL_RAW_DATA, $iv));
	}

	protected static function padCryptKey(string $key, string $cipher): string
	{
		if (preg_match('/\d{3,}/', $cipher, $match)) {
			$length    = (int)$match[0] / 8;
			$keyLength = strlen($key);
			if ($length === $keyLength) {
				return $key;
			}

			$new_key = str_repeat(chr(0), $length);
			for ($i = 0; $i < $keyLength; $i++) {
				$new_key[$i % $length] = $new_key[$i % $length] ^ $key[$i];
			}
			return $new_key;
		}

		return $key;
	}

	protected function getCryptColumns($table): array
	{
		$columns = self::$cryptConf[$this->dbname][$table]['columns'] ?? [];
		if (is_array($columns)) {
			return $columns;
		}

		return array_filter(array_map('trim', explode(',', (string)$columns)));
	}

	protected function cryptColumnCallback(array &$data, array $columns, callable $callback): void
	{
		$count = 0;
		if (array_is_list($data)) {
			foreach ($data as &$v) {
				foreach ($columns as $key) {
					if (isset($v[$key])) {
						$count++;
						$v[$key] = $callback($v[$key]);
					}
				}
			}
			unset($v);
		} else {
			foreach ($columns as $key) {
				if (isset($data[$key])) {
					$count++;
					$data[$key] = $callback($data[$key]);
				}
			}
		}
		if ($count) {
			debug($data, 'DB CRYPT CALLBACK (' . $count . ')');
		}
	}

	protected function getDecryptColumnQuery(string $column, string $salt = '', string $cipher = ''): string
	{
		$cipher = strtolower($cipher ?: self::$cipher);
		$salt   = $salt ?: self::$salt;

		if (str_contains($cipher, 'cbc')) {
			return sprintf("IF(LEFT(%s, 2) = 'x:', AES_DECRYPT(UNHEX(SUBSTRING(%s, 35)), '%s', UNHEX(SUBSTRING(%s, 3, 32))), %s)", $column, $column, $salt, $column, $column);
		}

		return sprintf("IF(LEFT(%s, 2) = 'x:', AES_DECRYPT(UNHEX(SUBSTRING(%s, 3)), '%s'), %s)", $column, $column, $salt, $column);
	}

	protected function applyDecryptColumnQueryCallback(array $matches): string
	{
		if (in_array($matches['column'], $this->cryptColumns, true)) {
			return self::getDecryptColumnQuery($matches['column_with_table']) . ' ' . $matches['operator'];
		}

		return $matches[0];
	}
}
