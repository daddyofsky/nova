<?php
namespace Nova\Http;

use Error;

class MultipartParser
{
	public function parse($file = 'php://input'): false|array
	{
		if (!$file || !($fp = @fopen($file, 'rb'))) {
			return false;
		}

		if (!$boundary = $this->getBoundary($fp)) {
			return $this->parseUrlEncoded($fp);
		}

		$data = [];
		while (true) {
			$header = $this->parseHeader($fp);
			if (!$header) {
				break;
			}

			if (empty($header['filename'])) {
				$data[] = $this->parseData($fp, $header, $boundary);
			} else {
				$data[] = $this->parseFile($fp, $header, $boundary);
			}
		}
		fclose($fp);

		return array_merge_recursive(...$data);
	}

	protected function getBoundary($fp): false|string
	{
		$boundary = fgets($fp);
		if (!$boundary || !str_starts_with($boundary, '--')) {
			return false;
		}

		return substr($boundary, 0, -2);
	}

	protected function parseUrlEncoded($fp): array
	{
		parse_str(fgets($fp), $data);
		fclose($fp);
		return $data;
	}

	protected function parseHeader($fp): array
	{
		$header = [];
		while ($line = fgets($fp)) {
			if ($line === "\r\n") {
				break;
			}
			$header += $this->parseHeaderLine($line);
		}

		return $header;
	}

	protected function parseHeaderLine($str): array
	{
		$result = [];
		$parts = explode('; ', substr($str, 0, -2));
		foreach ($parts as $v) {
			[$key, $value] = preg_split('/(: |=)/', $v, 2);
			if ($key === 'name') {
				$value = substr($value, 1, -1); // trim quotes
			}
			$result[$key] = $value;
		}

		return $result;
	}

	protected function parseData($fp, $header, $boundary): array
	{
		$value = $this->readData($fp, $boundary);

		return $this->getValueArray($header['name'], $value);
	}

	protected function readData($fp, $boundary): string
	{
		$value = '';
		while ($line = fgets($fp)) {
			if (str_starts_with($line, $boundary)) {
				return substr($value, 0, -2);
			}
			$value .= $line;
		}

		return $value;
	}

	protected function parseFile($fp, $header, $boundary): array
	{
		try {
			$tmp_file = $this->writeFileData($fp, $boundary);
			$data = [
				'name'      => $header['filename'],
				'full_path' => $header['filename'],
				'type'      => $header['Content-Type'] ?? '',
				'tmp_name'  => $tmp_file,
				'error'     => 0,
				'size'      => filesize($tmp_file),
			];
		} catch (Error $e) {
			$data = [
				'name'      => $header['filename'],
				'full_path' => $header['filename'],
				'type'      => '',
				'tmp_name'  => '',
				'error'     => $e->getCode(),
				'size'      => 0,
			];
		}
		$this->applyFileData($header['name'], $data);

		return $this->getValueArray($header['name'], $header['filename']);
	}

	protected function writeFileData($fp, $boundary): string
	{
		$tmp_file = tempnam(sys_get_temp_dir(), 'php');
		if ($wfp = fopen($tmp_file, 'wb')) {
			$buffer = '';
			while ($part = fgets($fp, 4096)) {
				if (str_starts_with($part, $boundary)) {
					fwrite($wfp, substr($buffer, 0, -2));
					break;
				}
				if ($buffer !== '') {
					fwrite($wfp, $buffer);
				}
				$buffer = $part;
			}
			fclose($wfp);
			return $tmp_file;
		}
		return '';
	}

	protected function getValueArray($name, $value): array
	{
		[$name, $keys] = $this->parseName($name);
		if (!$keys) {
			return [$name => $value];
		}

		$result = [];
		$this->initArrayKey($result, $name);
		$parent = &$result[$name];
		foreach ($keys as $key) {
			if ($key === '') {
				$key = 0;
			}
			$parent[$key] = [];
			$parent       = &$parent[$key];
		}
		$parent = $value;
		return $result;
	}

	protected function applyFileData($name, $data): void
	{
		[$name, $keys] = $this->parseName($name);
		if (!$keys) {
			$_FILES[$name] = $data;
			return;
		}

		$this->initArrayKey($_FILES, $name);

		$file_keys = ['name', 'full_path', 'type', 'tmp_name', 'error', 'size'];
		foreach ($file_keys as $file_key) {
			$this->initArrayKey($_FILES[$name], $file_key);
			$part = &$_FILES[$name][$file_key];
			foreach ($keys as $key) {
				if ($key === '') {
					$part[] = [];
					$key      = array_key_last($part);
				} else {
					$part[$key] = [];
				}
				$part = &$part[$key];
			}
			$part = $data[$file_key];
		}
	}

	protected function parseName($name): array
	{
		if (preg_match_all('/\[([^]]*)]/', $name, $matches)) {
			$name = substr($name, 0, strpos($name, '['));
			return [$name, $matches[1]];
		}

		return [$name, []];
	}

	protected function initArrayKey(&$array, $key): void
	{
		if (!isset($array[$key]) || !is_array($array[$key])) {
			$array[$key] = [];
		}
	}
}
