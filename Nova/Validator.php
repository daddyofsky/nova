<?php
namespace Nova;

use Nova\Exceptions\ValidationException;
use Nova\Exceptions\ValidationRuleException;
use Nova\Http\FormRequest;
use Nova\Support\ArrayData;
use Nova\Support\Crypt;
use Nova\Support\Str;

/**
 * [ options for Form.Checker.add() ]
 * - RegExp object
 * - function
 * - option string
 * . length:[min,]max - string length
 * . byte:[min,]max - string byte
 * . range:[min,]max - number range
 * . check:[min,]max - checkbox check count // add
 * . select:[min,]max - selectbox select count // add
 *
 * . alter[:max] - alternative
 * . glue:string - glue
 *
 * . empty - check if empty. (default)
 * . trim - trim value (not applied to original)
 * . apply:element - apply value to another element
 * . match:element - compare elements value
 *
 * . email, homepage, url, jumin, bizno, phone, mobile - pre defined check function
 * - option object
 * trim: true,
 * empty: true,
 * func: function,
 * regexp: pattern,
 * length: [min, max]
 * ...
 */
class Validator
{
	protected FormRequest $request;
	protected bool $stopOnFirstFailure = false;

	protected array $errors = [];

	// before / after (prepare / passed)

	// TODO : use FormRequest --> NEED NOT rules, messages, names

	public function __construct($request)
	{
		$this->request = $request;
	}

	public function stopOnFirstFailure(bool $flag = true): static
	{
		$this->stopOnFirstFailure = $flag;
		return $this;
	}

	/**
	 * @throws \Nova\Exceptions\ValidationException
	 * @throws \Nova\Exceptions\ValidationRuleException
	 */
	public function safe(array $rules = []): ArrayData
	{
		return new ArrayData($this->validated($rules));
	}

	/**
	 * @throws \Nova\Exceptions\ValidationException
	 * @throws \Nova\Exceptions\ValidationRuleException
	 */
	public function validated(array $rules = []): array
	{
		// before callback
		if (method_exists($this->request, 'beforeValidation')) {
			$this->request->beforeValidation();
		}

		$data = $this->request->all();

		$rules || $rules = $this->request->rules();
		if ($rules) {
			foreach ($rules as $key => $rule) {
				$value = $this->checkByRule($key, $this->parseRule($rule));
				if ($value === false) {
					if ($this->stopOnFirstFailure) {
						break;
					}
					continue;
				}
				$data[$key] = $value;
			}
		}

		if ($this->errors) {
			throw (new ValidationException())->errors($this->errors);
		}

		// after callback
		if (method_exists($this->request, 'afterValidation')) {
			$this->request->afterValidation($data);
		}

		return $data;
	}

	public function errors(): array
	{
		return $this->errors;
	}

	/**
	 * @throws \Nova\Exceptions\ValidationRuleException
	 */
	protected function checkByRule($key, $rules)
	{
		$errors = [];
		$bail = $this->stopOnFirstFailure; // stop on failure

		$value = trim($this->request[$key] ?? '');
		foreach ($rules as $rule => $params) {
			if ($rule === 'bail') {
				$bail = true;
				continue;
			}
			$method = 'rule' . Str::pascal($rule);
			if (method_exists($this, $method)) {
				$value  = $this->$method($value, $key, ...(array)$params);
				if ($value === false) {
					$errors[] = $this->getErrorMessage($key, $rule, $params);
					if ($bail) {
						break;
					}
				}
			} else {
				throw new ValidationRuleException(sprintf('[%s] %s - invalid rule.', $key, $rule));
			}
		}

		if ($errors) {
			$this->errors[$key] = array_unique($errors);
		}

		return $value;
	}

	protected function parseRule($rule): array
	{
		if (is_array($rule)) {
			return $this->parseArrayRule($rule);
		}
		return $this->parseStringRule($rule);
	}

	protected function parseArrayRule($rule): array
	{
		$result = [];
		foreach ($rule as $key => $value) {
			if (is_int($key)) {
				// ex) ['required'] --> ['required' => []]
				$result[$value] =  [];
			} else {
				$result[$key] = [$value];
			}
		}
		return $result;
	}

	protected function parseStringRule($rule): array
	{
		$result = [];
		$array = explode('|', $rule);
		foreach ($array as $value) {
			[$name, $value] = Str::explode(':', $value, 2, '');
			$result[$name] = explode(',', $value);
		}
		return $result;
	}

	protected function getErrorMessage($key, $rule, $params): string
	{
		return $this->request->message($key, $rule, $params);
	}

	protected function getSize($value, $key): int
	{
		if (isset($_FILES[$key])) {
			if (isset($_FILES[$key]['tmp_name']) && file_exists($_FILES[$key]['tmp_name'])) {
				return filesize($_FILES[$key]['tmp_name']);
			}
			return 0;
		}

		if (is_countable($value)) {
			return count($value);
		}

		if (is_string($value)){
			return Str::length($value);
		}

		return $value;
	}

	////////////////////////////////////////////////////////////////////////////////////////////////
	// data type

	public function ruleBool($value): int|false
	{
		$value = strtoupper($value);
		if ($value === '1' || $value === 'Y' || $value === 'YES' || $value === 'TRUE') {
			return 1;
		}
		if ($value === '0' || $value === 'N' || $value === 'NO' || $value === 'FALSE') {
			return 0;
		}
		return false;
	}

	public function ruleInteger($value): int|false
	{
		return filter_var($value, FILTER_VALIDATE_INT);
	}

	public function ruleFloat($value): float|false
	{
		return filter_var($value, FILTER_VALIDATE_FLOAT);
	}

	public function ruleNumeric($value): int|float|false
	{
		return is_numeric($value) ? $value : false;
	}

	public function ruleArray($value): array|false
	{
		return is_array($value) ? $value : false;
	}

	////////////////////////////////////////////////////////////////////////////////////////////////

	public function ruleRequired($value)
	{
		return !blank($value) ? $value : false;
	}

	public function ruleUnique($value, $key, $table = null, $column = null)
	{
		if (!$table) {
			throw new ValidationRuleException(sprintf('[%s] unique:<table>[,column] - table is missing.', $key));
		}

		$column || $column = $key;

		return blank(Model::make($table)->findColumn([$column, $value], $column)) ? $value : false;
	}

	public function ruleSize($value, $key, $size = null)
	{
		if ($size === null) {
			throw new ValidationRuleException(sprintf('[%s] size:<value> - value is missing.', $key));
		}

		return $this->getSize($value, $key) === $size ? $value : false;
	}

	public function ruleMin($value, $key, $min = null)
	{
		if ($min === null) {
			throw new ValidationRuleException(sprintf('[%s] min:<value> - value is missing.', $key));
		}

		return $this->getSize($value, $key) >= (int)$min ? $value : false;
	}

	public function ruleMax($value, $key, $max = null)
	{
		if ($max === null) {
			throw new ValidationRuleException(sprintf('[%s] max:<value> - value is missing.', $key));
		}

		return $this->getSize($value, $key) <= (int)$max ? $value : false;
	}

	public function ruleBetween($value, $key, $min = null, $max = null)
	{
		if ($min === null) {
			throw new ValidationRuleException(sprintf('[%s] between:[min,]<max> - value is missing.', $key));
		}

		if (!$max) {
			$max = (int)$min;
			$min = 0;
		}

		$size = $this->getSize($value, $key);

		return $size >= $min && $size <= $max ? $value : false;
	}

	public function ruleRange($value, $key, $min = null, $max = null)
	{
		if ($min === null) {
			throw new ValidationRuleException(sprintf('[%s] range:[min,]<max> - value is missing.', $key));
		}

		return $this->ruleBetween($value, $key, $min, $max);
	}

	public function ruleLength($value, $key, $min = null, $max = null)
	{
		if ($min === null) {
			throw new ValidationRuleException(sprintf('[%s] length:[min,]<max> - value is missing.', $key));
		}

		return $this->ruleBetween(Str::length($value), $key, $min, $max);
	}

	public function ruleByte($value, $key, $min = null, $max = null)
	{
		if ($min === null) {
			throw new ValidationRuleException(sprintf('[%s] byte:[min,]<max> - value is missing.', $key));
		}

		return $this->ruleBetween(strlen($value), $key, $min, $max);
	}

	public function ruleCheck($value, $key, $min = null, $max = null)
	{
		if (!$min === null) {
			throw new ValidationRuleException(sprintf('[%s] check:[min,]<max> - value is missing.', $key));
		}

		if (is_array($value)) {
			$count = count($value);
		} else {
			$count = count(preg_split('/[,:|]+/', $value));
		}
		return $this->ruleBetween(strlen($value), $key, $min, $max);
	}

	public function ruleSelect($value, $key, $min = null, $max = null)
	{
		if ($min === null) {
			throw new ValidationRuleException(sprintf('[%s] select:[min,]<max> - value is missing.', $key));
		}

		return $this->ruleCheck($value, $key, $min, $max);
	}

	public function ruleRegex($value, $key, $pattern = null)
	{
		if (!$pattern) {
			throw new ValidationRuleException(sprintf('[%s] regex:<pattern> - pattern is missing.', $key));
		}

		return preg_match($pattern, $value) ? $value : false;
	}

	public function ruleNotRegex($value, $key, $pattern = null)
	{
		if (!$pattern) {
			throw new ValidationRuleException(sprintf('[%s] not_regex:<pattern> - pattern is missing.', $key));
		}

		return !preg_match($pattern, $value) ? $value : false;
	}

	////////////////////////////////////////////////////////////////////////////////////////////////

	public function ruleAlpha($value)
	{
		return preg_match('/^[a-zA-Z]+$/', $value) ? $value : false;
	}

	public function ruleEmail($value)
	{
		if (is_array($value)) {
			$value = implode('@', $value);
		}

		return filter_var($value, FILTER_VALIDATE_EMAIL);
	}

	public function ruleDomain($value)
	{
		return filter_var($value, FILTER_VALIDATE_DOMAIN);
	}

	public function ruleUrl($value)
	{
		return filter_var($value, FILTER_VALIDATE_URL);
	}

	public function ruleIp($value)
	{
		return filter_var($value, FILTER_VALIDATE_IP);
	}

	public function ruleIpv4($value)
	{
		return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
	}

	public function ruleIpv6($value)
	{
		return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
	}

	public function ruleMacAddress($value)
	{
		return filter_var($value, FILTER_VALIDATE_MAC);
	}

	public function ruleFile($value, $key)
	{
		if (empty($_FILES[$key])) {
			return false;
		}

		if (is_array($_FILES[$key]['tmp_name'])) {
			foreach ($_FILES[$key]['tmp_name'] as $tmp_file) {
				if (!is_uploaded_file($tmp_file)) {
					return false;
				}
			}
			return $_FILES[$key];
		}

		return is_uploaded_file($_FILES[$key]['tmp_name']) ? $_FILES[$key] : false;
	}

	public function ruleJumin($value): false|string
	{
		if (is_array($value)) {
			$value = implode('-', $value);
		}

		$value = substr($value, 0, 6) . '-' . substr($value, -7);
		if (!preg_match('/^\d{6}-\d{7}$/', $value)) {
			return false;
		}

		$num = preg_replace('/\D/', '', $value);
		$sum = 0;
		$last = (int) substr($num, -1);
		$bases = '234567892345';
		for ($i = 0; $i < 12; $i++) {
			$sum += $num[$i] * $bases[$i];
		}
		$mod = $sum % 11;
		return (11 - $mod) % 10 === $last ? $value : false;
	}

	public function ruleBizNo($value): false|string
	{
		if (is_array($value)) {
			$value = implode('-', $value);
		}

		$num = preg_replace('/\D/', '', $value);
		$value = substr($value, 0, 3) . '-' . substr($num, 3, 2) . '-' . substr($value, 5, 5);
		if (!preg_match('/^\d{3}-\d{2}-\d{5}$/', (string)$value)) {
			return false;
		}

		$cVal = 0;
		for ($i = 0; $i < 8; $i++) {
			$_tmp = $i % 3;
			if ($_tmp === 0) {
				$cKeyNum = 1;
			} elseif ($_tmp === 1) {
				$cKeyNum = 3;
			} else {
				$cKeyNum = 7;
			}
			$cVal += (int) $num[$i] * $cKeyNum % 10;
		}
		$li_temp = (string)($num[$i] * 5);
		$cVal += (int)$li_temp[0] + (int)($li_temp[1] ?? 0);

		$last = $cVal % 10 % 10;
		$last = $last > 0 ? 10 - $last : $last;

		return (int)$num[9] === $last ? $value : false;
	}

	public function rulePhone($value)
	{
		if (is_array($value)) {
			$value = implode('-', $value);
		}

		return preg_match('/^0\d{1,2}-[1-9]\d{2,3}-\d{4}$/', $value) ? $value : false;
	}

	public function ruleMobile($value)
	{
		if (is_array($value)) {
			$value = implode('-', $value);
		}

		return preg_match('/^01[016-9]-[1-9]\d{2,3}-\d{4}$/', $value) ? $value : false;
	}

	////////////////////////////////////////////////////////////////////////////////////////////////
	// before / after action
	// rule 처럼 설정할 수 있는 방법 필요

	public function ruleDefault($value, $key, $default)
	{
		return !blank($value) ? $value : $default;
	}

	public function ruleHash($value): string
	{
		return Crypt::hash($value);
	}

	public function ruleGlue($value, $key, $param = null)
	{
		if (is_array($value)) {
			return implode($param, $value);
		}

		// TODO : key based??
		// ex) key1, key2
		return $value;
	}

	public function ruleAlter($value, $key, $params = null)
	{
		// TODO: remove??
	}

	public function ruleMatch($value, $key, $params = null)
	{
		return $value === $this->request[$params] ? $value : false;
	}

	public function ruleApply($value, $key, $params = null)
	{
		return $this->request[$params] = $value;
	}
}
