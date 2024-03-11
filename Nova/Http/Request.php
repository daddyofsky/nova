<?php
namespace Nova\Http;

use App\Providers\RequestServiceProvider;
use Nova\Support\ArrayData;
use Nova\Support\Str;
use Nova\Traits\SingletonTrait;

class Request extends ArrayData
{
	use SingletonTrait;

	public const TYPE_CODE  = 1;
	public const TYPE_INT   = 2;
	public const TYPE_FLOAT = 3;

	protected string $provider = RequestServiceProvider::class;

	protected string $method = '';
	protected string $uri    = '';
	protected string $path   = '';
	protected array  $args   = [];

	public function __construct()
	{
		parent::__construct($this->init());
	}

	protected function init(): array
	{
		$data = match ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
			'GET'   => $_GET,
			'POST'  => $_POST,
			default => (new MultipartParser())->parse(),
		};

		// escape
		if ($escape = [$this->provider, 'getEscape']()) {
			foreach ($data as $key => $value) {
				if (isset($escape[$key])) {
					$data[$key] = $this->escape($value, $escape[$key]);
				}
			}
		}

		return $data;
	}

	public function setArgs(array $args): void
	{
		$this->args = $args;
	}

	public function args(): array
	{
		return $this->args;
	}

	public function item(string|array|true $key, mixed $default = '', ?array $data = null): mixed
	{
		$data = $data ?? (array)$this;

		if ($key === true) {
			return new ArrayData($data);
		}
		if (!is_array($key)) {
			return $data[(string)$key] ?? $default;
		}

		$result = new ArrayData();
		foreach ($key as $k => $v) {
			if (is_int($k)) {
				$result[$v] = $data[$v] ?? '';
			} elseif (isset($data[$k])) {
				$result[$k] = $data[$k];
			} else {
				$result[$k] = '';
			}
		}

		return $result;
	}

	public function get(string|array|true $key = true, mixed $default = ''): mixed
	{
		if (!$this->isPost()) {
			return $this->item($key, $default);
		}

		return $this->item($key, $default, $_GET);
	}

	public function post(string|array|true $key = true, mixed $default = ''): mixed
	{
		if ($this->isPost()) {
			return $this->item($key, $default);
		}

		return $this->item($key, $default, $_POST);
	}

	public function request(string|array|true $key = true, mixed $default = ''): mixed
	{
		static $request;

		if (!$request) {
			$request = (array)$this + $_POST + $_GET;
		}

		return $this->item($key, $default, $request);
	}

	public function cookie(string|array|true $key = true, mixed $default = ''): mixed
	{
		return $this->item($key, $default, $_COOKIE);
	}

	public function session(string|array|true $key = true, mixed $default = ''): mixed
	{
		return $this->item($key, $default, $_SESSION);
	}

	public function method(): string
	{
		if ($this->method) {
			return $this->method;
		}

		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		if ($method !== 'POST') {
			return $this->method = $method;
		}

		$method = strtoupper($_REQUEST['_method'] ?? 'POST');
		if (in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'PATCH', 'PURGE', 'TRACE'], true)) {
			return $this->method = $method;
		}

		return $this->method = 'POST';
	}

	public function uri(): string
	{
		if ($this->uri) {
			return $this->uri;
		}

		$uri = $_SERVER['REQUEST_URI'];

		// /public/foo/bar?a=A --> /foo/bar?a=A
		$uri = preg_replace('/^\/public\//', '/', $uri);

		return $this->uri = $uri;
	}

	public function domain(): string
	{
		return $_SERVER['HTTP_HOST_ORG'] ?? $_SERVER['HTTP_HOST'];
	}

	public function baseDomain(string $host = ''): string
	{
		if (!$host) {
			$host = $this->domain();
		}
		// ip
		if (preg_match('/^[0-9.]+$/', $host)) {
			return $host;
		}
		// remove sub host
		return preg_replace('/^(www|img|m|home|desk|mail|contact|calendar|cafe|tax|marketing|site|stat|setting)\./', '', $host);
	}

	public function path(): string
	{
		if ($this->path) {
			return $this->path;
		}

		if (isset($_SERVER['PATH_INFO'])) {
			// ex) /index.php/foo/bar?a=A --> /foo/bar
			return $this->path = $_SERVER['PATH_INFO'];
		}

		$path = explode('?', $this->uri(), 2)[0];
		if ($path !== '/') {
			// /foo/bar
			$path = rtrim($path, '/') ?: '/';
			return $this->path = $path;
		}

		return $this->path = '/';
	}

	public function referer(): string
	{
		return $_SERVER['HTTP_REFERER'] ?? '';
	}

	public function route(string|array|ArrayData|bool $path = '', string|array|ArrayData|bool $params = true): string
	{
		if (!is_string($path)) {
			$params = $path;
			$path = '';
		}

		if ($path) {
			if (preg_match('/^(https?:)?\/\//', $path)) {
				$uri = Str::tidyUrl($path);
			} elseif ($path[0] === '/') {
				$uri = Str::tidyPath($path);
			} else {
				$uri = Str::tidyPath($this->path() . '/' . $path);
			}
		} else {
			$uri = Str::tidyUrl($this->referer());
		}

		if (is_string($params)) {
			parse_str($params, $tmp);
			$params = $tmp;
		} elseif ($params === true) {
			$params = $this->get();
		}
		if ($params) {
			$params = array_filter((array)$params, fn($v) => $v !== null && $v !== '');
			if ($query = Str::tidyQuery($params)) {
				$uri .= (str_contains($uri, '?') ? '&' : '?') . $query;
			}
		}

		return $uri;
	}

	public function escape(mixed $value, int $type = self::TYPE_CODE): mixed
	{
		// TODO : XSS
		return match ($type) {
			self::TYPE_CODE => preg_replace('/[^\w.,|\/-].*/', '', $value),
			self::TYPE_INT => (int)$value,
			self::TYPE_FLOAT => (float)$value,
			default => $value,
		};
	}

	public function isPost(): bool
	{
		return ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
	}

	public function isAjax(): bool
	{
		static $flag = null;

		if ($flag !== null) {
			return $flag;
		}

		if (conf('ajax.use')) {
			return $flag = true;
		}
		if (!empty($_REQUEST['ajax'])) {
			return $flag = true;
		}
		if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
			return $flag = true;
		}

		return $flag = false;
	}

	public function __debugInfo(): array
	{
		return ['@see' => __METHOD__ . '()'] + array_filter(get_object_vars($this), function ($key) {
				return $key !== '__escape';
			}, ARRAY_FILTER_USE_KEY);
	}
}
