<?php
namespace Nova\Support;

use ArrayAccess;

class Arr
{
	public static function only(array $array, array|string $keys): array
	{
		$keys = is_array($keys) ? $keys : array_slice(func_get_args(), 1);

		$result = [];
		foreach ($keys as $key) {
			if (isset($array[$key])) {
				$result[$key] = $array[$key];
			}
		}
		return $result;
	}

	public static function except(array $array, array|string $keys): array
	{
		$keys = is_array($keys) ? $keys : array_slice(func_get_args(), 1);

		$result = [];
		foreach ($array as $key => $value) {
			if (!isset($keys[$key])) {
				$result[$key] = $value;
			}
		}
		return $result;
	}

	public static function join(string $glue, array $array): string
	{
		if (count($array) === 0) {
			return '';
		}

		return implode($glue, array_filter($array, fn ($v) => $v !== ''));
	}

	public static function divide(array $array): array
	{
		return [array_keys($array), array_values($array)];
	}

	public static function attach(array|ArrayAccess $array, array|ArrayAccess $data, $by, $to): array|ArrayAccess
	{
		[$array_key, $data_key] = explode(':', $by);
		$data_key || $data_key = $array_key;

		if (array_is_list((array)$data)) {
			$data = array_column((array)$data, null, $data_key);
		}
		foreach ($array as &$value) {
			$value[$to] = $data[$value[$array_key]] ?? [];
		}

		return $array;
	}

	public static function attachMany(array|ArrayAccess $array, array|ArrayAccess $data, $by, $to): array|ArrayAccess
	{
		[$array_key, $data_key] = explode(':', $by);
		$data_key || $data_key = $array_key;

		foreach ($array as &$value) {
			$value[$to] = [];
			foreach ($data as $v) {
				if ($value[$array_key] === $v[$data_key]) {
					$value[$to][] = $v;
				}
			}
		}

		return $array;
	}
}
