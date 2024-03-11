<?php
namespace Nova;

use Nova\Exceptions\Handler;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Http\Session;

class App
{
	protected string $target    = '';
	protected array  $providers = [];

	protected static ?array $conf      = null;
	protected static array  $times     = [];
	protected static array  $instances = [];

	public function boot(): void
	{
		$this->prepare();
		$response = $this->handle(request());
		$this->terminate($response);
	}

	public function prepare(): void
	{
		self::time('PREPARE');
		$this->setErrorHandler();
		$this->setProviders();
	}

	public function handle(Request $request): Response
	{
		self::time('HANDLE');
		debug((array)$request, 'REQUEST');
		debug(Session::flash(), 'SESSION FLASH');
		
		return Route::getMatchedRoute($this->target, $request)
			?->dispatch($request)
			?? response();
	}

	public function terminate(?Response $response = null): void
	{
		self::time('TERMINATE');

		($response ?? response())->send();

		$this->summary();
		if (conf('app.debug')) {
			Debug::debugOutputHandler();
		}
	}

	protected function setErrorHandler(): void
	{
		set_error_handler([Handler::class, 'errorHandler']);
		set_exception_handler([Handler::class, 'exceptionHandler']);
	}

	protected function setProviders(): void
	{
		//
	}
	
	public static function make(string $class = '', ...$args): object
	{
		if ($class) {
			return self::$instances[$class] ?? self::$instances[$class] = new $class(...$args);
		}
		
		return self::$instances['app'] ?? self::$instances['app'] = new static();
	}

	public static function alias(object $object, string $alias): object
	{
		$id = get_class($object);
		if (!isset(self::$instances[$id])) {
			self::$instances[$id] = $object;
		}

		return self::$instances[$alias] = &self::$instances[$id];
	}

	public static function conf(string $key, mixed $default = null): mixed
	{
		if (!isset(self::$conf)) {
			self::$conf = include APP_ROOT . '/config/app.php';
		}

		return self::$conf[$key] ?? $default;
	}

	public static function setConf(string $key, mixed $value): void
	{
		self::$conf[$key] = $value;
	}

	public static function debug(mixed $msg, string $label = '', mixed $extra = null): void
	{
		if (!self::conf('app.debug') || self::conf('ajax.use')) {
			return;
		}
		
		// TODO : add to json if ajax.use

		if (self::conf('app.env') === 'live' && self::conf('debug.log')) {
			$msg = $label ? sprintf('[%s] %s', $label, $msg) : $msg;
			Log::save($msg, Log::LOG_DEBUG, 'debug');
		}
		Debug::output($msg, $label, $extra);
	}

	public static function time(string $name): void
	{
		self::$times[$name] = microtime(true);
	}

	public static function getTimes(): array
	{
		return static::$times;
	}

	protected function summary(): void
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !(conf('app.debug') || conf('app.env') === 'dev')) {
			return;
		}
		$timestamp = &self::$times;
		if (count($timestamp) === 1) {
			$timestamp['END'] = microtime(true);
		}

		$start  = reset($timestamp);
		$total  = end($timestamp) - $start;
		$from   = 0;
		$name   = '';
		$data   = [];
		$data[] = '----------------------------------';
		foreach ($timestamp as $k => $v) {
			if (!$from) {
				$data[] = sprintf(' %s : %s', str_pad('START', 10), date('Y-m-d H:i:s', (int)$v));
			} else {
				$term    = $v - $from;
				$percent = 100 * $term / $total;
				$data[]  = sprintf(' %s : %s (%s%%)', str_pad($name, 10), round($term, 5), round($percent, 1));
			}
			$from = $v;
			$name = $k;
		}
		$data[] = '----------------------------------';
		$data[] = sprintf(' %s : %s', str_pad('TOTAL', 10), $total = round($total, 5));
		$data[] = '----------------------------------';
		$data[] = sprintf(' %s : %s MB (%s MB)', str_pad('MEMORY', 10), $memory_usage = round(memory_get_usage() / 1048576, 3), $memory_peak = round(memory_get_peak_usage() / 1048576, 3));
		$data[] = '----------------------------------';

		if (conf('app.debug')) {
			debug($data, 'TIME');
		} else {
			echo "\n<!--\n";
			echo implode("\n", $data);
			echo '-->';
		}

		if (conf('app.env') === 'dev') {
			echo "\n";
			echo '<div class="_debug_version">';
			echo sprintf('PHP : <strong>%s</strong> / TOTAL : <strong>%s</strong>', PHP_VERSION, $total);
			echo sprintf(' / MEMORY : <strong>%s</strong> MB (<strong>%s</strong> MB)', $memory_usage, $memory_peak);
			echo '</div>';
			echo '<style>._debug_version { all:revert; position:fixed; right:0; bottom:0; z-index:9999; margin:0; padding:2px 5px; border:1px solid #ddd; background:#ffd; font-family:sans-serif; } ._debug_version strong { all:revert; color:blue; } </style>';
		}
	}
}
