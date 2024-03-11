<?php
namespace Nova\Support;

class Str
{
	public static function length(string $value): int
	{
		$byte = strlen($value);

		$length = 0;
		for ($i = 0; $i < $byte;) {
			$ord = ord($value[$i]);
			if ($ord < 0xc0) {
				// 1byte
				$i++;
				$length++;
			} elseif ($ord < 0xe0) {
				// 2byte
				$i   += 2;
				$length += 2;
			} elseif ($ord < 0xf0) {
				// 3byte
				$i   += 3;
				$length += 2;
			} else {
				// 4byte
				$i   += 4;
				$length += 2;
			}
		}
		return $length;
	}

	public static function cut(string $value, int $limit, string $suffix = '..'): string
	{
		$length = strlen($value);
		if ($limit >= $length || $limit < 1) {
			return $value;
		}
		for ($i = 0, $len2 = 0; $i < $length;) {
			$ord = ord($value[$i]);
			if ($ord < 0xc0) {
				// 1byte
				$i++;
				$len2++;
			} elseif ($ord < 0xe0) {
				// 2byte
				$i    += 2;
				$len2 += 2;
			} elseif ($ord < 0xf0) {
				// 3byte
				$i    += 3;
				$len2 += 2;
			} else {
				// 4byte
				$i    += 4;
				$len2 += 2;
			}
			if ($len2 >= $limit) {
				return substr($value, 0, $i) . ($length > $i ? $suffix : '');
			}
		}
		return $value;
	}

	public static function explode(string $separator, ?string $value, int $limit = 0, mixed $default = ''): array
	{
		$result = explode($separator, $value ?? '', $limit ?: PHP_INT_MAX);
		if ($limit > 1 && count($result) < $limit) {
			return array_pad($result, $limit, $default);
		}

		return $result;
	}

	public static function split(string $pattern, ?string $value, int $limit = 0, mixed $default = ''): array
	{
		$result = preg_split($pattern, $value ?? '', $limit ?: PHP_INT_MAX);
		if ($limit > 0 && count($result) < $limit) {
			return array_pad($result, $limit, $default);
		}
		
		return $result;
	}

	public static function tidyUrl(string $url): string
	{
		$result = '';
		$info = parse_url($url);
		if (isset($info['host'])) {
			$result .= isset($info['scheme']) ? $info['scheme'] .= '://' : '//';
			isset($info['user']) && $result .= $info['user'] .= ':';
			(isset($info['user']) || isset($info['pass'])) && $result .= ($info['pass'] ?? '') . '@';
			$result .= $info['host'];
		}
		isset($info['path']) && $result .= self::tidyPath($info['path']);
		if (isset($info['query']) && $query = self::tidyQuery($info['query'])) {
			$result .= '?' . $query;
		}
		isset($info['fragment']) && $info['fragment'] !== '' && $result .= '#' . $info['fragment'];
		
		return $result;
	}
	
	public static function tidyPath(string $path): string
	{
		$path = trim($path);
		$root = ($path[0] ?? '') === '/' ? '/' : '';

		$array = array_map('trim', explode('/', preg_replace('/\/\/+/', '/', ltrim($path, '/'))));
		foreach ($array as $key => $value) {
			if ($value === '') {
				unset($array[$key]);
				continue;
			}
			if ($value === '..') {
				unset($array[$key]);
				for ($i = $key - 1; $i >= 0; $i--) {
					if (isset($array[$i]) && $array[$i] !== '') {
						unset($array[$i]);
						break;
					}
				}
				continue;
			}
			if (($value[0] ?? '') === '.') {
				unset($array[$key]);
			}
		}

		return $root . implode('/', $array);
	}

	public static function tidyQuery(string|array $query): string
	{
		if (is_array($query)) {
			$array = $query;
		} else {
			parse_str($query, $array);
		}
		array_walk_recursive($array, fn (&$v) => $v === '' && $v = null);
		if ($array = array_filter($array, fn ($v) => $v !== '')) {
			return http_build_query($array);
		}
		return '';
	}

	/**
	 * to snake case. ex) snake_case_string
	 */
	public static function snake(string $value, $delimiter = '_'): string
	{
		return strtolower(preg_replace('/([A-Z])/', $delimiter . '$1', lcfirst($value)));
	}

	/**
	 * to lower camel case. ex) camelCaseString
	 */
	public static function camel(string $value): string
	{
		return preg_replace_callback('/[ _-]+([a-z])/i', fn($match) => strtoupper($match[1]), lcfirst($value));
	}

	/**
	 * to pascal case (upper camel case). ex) PascalCaseString
	 */
	public static function pascal(string $value): string
	{
		return preg_replace_callback('/[ _-]+([a-z])/i', fn($match) => strtoupper($match[1]), ucfirst($value));
	}

	public static function basename(string $value): string
	{
		if (str_contains($value, '\\')) {
			return basename(str_replace('\\', '/', $value));
		}

		return basename($value);
	}
}
