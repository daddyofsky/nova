<?php
namespace Nova\Http;

use Error;
use Nova\Exceptions\NotAuthorizedException;
use Nova\Support\Arr;
use Nova\Support\ArrayData;
use Nova\Support\Reflection;
use Nova\Validator;

/**
 * # Request
 * @method mixed item(string|array|true $key, mixed $default = '', ?array $data = null)
 * @method mixed get(string|array|true $key = true, mixed $default = '')
 * @method mixed post(string|array|true $key = true, mixed $default = '')
 * @method mixed request(string|array|true $key = true, mixed $default = '')
 * @method mixed cookie(string|array|true $key = true, mixed $default = '')
 * @method mixed session(string|array|true $key = true, mixed $default = '')
 * @method string method()
 * @method string uri()
 * @method string domain()
 * @method string baseDomain(string $host = '')
 * @method string path()
 * @method string referer()
 * @method string route(string|array|ArrayData|bool $path = '', array|ArrayData|bool $params = true)
 * @method mixed escape(mixed $value, int $type = Request::TYPE_CODE)
 * @method bool isPost()
 * @method bool isAjax()
 *
 * # Validator
 * @method Validator stopOnFirstFailure(bool $flag = true)
 * @method Validator getFilteredOnly(bool $flag = true)
 * @method array validate(array $rules = [])
 * @method array validated(array $rules = [])
 * @method ArrayData safe(array $rules = [])
 * @method array errors()
 */
#[\AllowDynamicProperties]
class FormRequest extends ArrayData
{
	protected Request $request;
	protected ?Validator $validator = null;

	protected array $rules = [];
	protected array $messages = [];
	protected array $names = [];

	protected array $commonMessages = [
		'authorize' => 'LANG:권한이 없습니다.',
		'fallback'  => 'LANG:{name} 항목이 바르지 않습니다.',
		'required'  => 'LANG:{name} 항목 값이 없습니다.',
	];

	public function __construct(Request $request)
	{
		$this->request = $request;
		parent::__construct($request);

		$this->messages += $this->commonMessages;
		
		if (method_exists($this, 'authorize')) {
			$parameters = Reflection::bindParameters([$this, 'authorize'], $request->args());
			if ($this->authorize(...$parameters) === false) {
				throw new NotAuthorizedException($this->message('authorize'));
			}
		}
	}

	public function __call($method, $args)
	{
		if (method_exists($this->request, $method)) {
			return $this->request->$method(...$args);
		}

		$validator = $this->validator();
		if (method_exists($validator, $method)) {
			return $validator->$method(...$args);
		}

		throw new Error(sprintf('Error: Call to undefined method %s::%s()', static::class, $method));
	}

	public function setValidator(Validator $validator): static
	{
		$this->validator = $validator;

		return $this;
	}

	protected function validator(): Validator
	{
		if ($this->validator) {
			return $this->validator;
		}

		return $this->validator = new Validator($this);
	}

	public function rules(): array
	{
		return $this->rules;
	}

	public function rulesOnly(...$keys): array
	{
		return Arr::only($this->rules, $keys);
	}

	public function rulesExcept(...$keys): array
	{
		return Arr::except($this->rules, $keys);
	}

	public function message($key, $rule = '', $params = []): string
	{
		$msg = $this->messages[$key . '.' . $rule]
			?? $this->messages[$key]
			?? $this->messages[$rule]
			?? $this->messages['fallback'];

		$msg = str_replace(['{name}', ':attribute'], $this->names[$key] ?? $key, $msg);

		return _T($msg, ...$params);
	}

	public function name($key): array
	{
		return $this->names[$key] ?? $key;
	}

	public function __debugInfo(): array
	{
		return ['@see' => __METHOD__ . '()', 'storage' => (array)$this] + get_object_vars($this);
	}
}
