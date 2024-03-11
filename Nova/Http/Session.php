<?php
namespace Nova\Http;

// TODO : db session
// @see https://www.php.net/manual/en/session.customhandler.php

use ArrayObject;

Session::init();

class Session
{
	protected static string $id = '';

	public static function init(): void
	{
		// session
		//session_set_cookie_params(0, '/' . (self::isSSL() ? '; SameSite=None' : ''), '.' . self::getBaseDomain(), self::isSSL(), true);
		session_cache_limiter('');
		session_id(($_COOKIE[session_name()] ?? '') ?: self::getId());

		session_cache_expire(180); // 3 hours
		ini_set('session.gc_maxlifetime', 10800); // 3 hours

		session_start();
	}

	public static function getId(): string
	{
		if (self::$id) {
			return self::$id;
		}

		[$a, $b] = explode('.', uniqid('', true));
		return self::$id = substr(sprintf('S-%s%s%s', $a, date('ymd'), $b), 0, 30);
	}

	public static function set(string|array $key, $value = null): void
	{
		if (is_array($key)) {
			foreach ($key as $k => $v) {
				$_SESSION[$k] = $v;
			}
			return;
		}

		$_SESSION[$key] = $value;
	}

	public static function get(string|array $key = '', $default = null)
	{
		if (!$key) {
			return $_SESSION;
		}

		if (is_array($key)) {
			$result = [];
			foreach ($key as $k => $v) {
				if (is_int($k)) {
					$result[$v] = $_SESSION[$v] ?? null;
				} else {
					$result[$k] = $_SESSION[$k] ?? $v;
				}
			}
			return $result;
		}

		return $_SESSION[$key] ?? $default;
	}

	public static function setFlash(string|array|ArrayObject $key, mixed $value = null): void
	{
		$data = is_string($key) ? [$key => $value] : (array)$key;

		if (isset($_SESSION['_flash'])) {
			$_SESSION['_flash'] += $data;
		} else {
			$_SESSION['_flash'] = $data;
		}
	}

	public static function flash(string $key = '')
	{
		static $flashData = null;

		if ($flashData === null) {
			$flashData = $_SESSION['_flash'] ?? [];
			unset($_SESSION['_flash']);
		}

		if ($key) {
			return $flashData[$key] ?? null;
		}

		return $flashData;
	}

	public static function remove($key): void
	{
		unset($_SESSION[$key]);
	}
}
