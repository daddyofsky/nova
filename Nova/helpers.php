<?php

use App\Services\AuthService;
use Nova\App;
use Nova\Exceptions\ErrorException;
use Nova\Exceptions\RedirectException;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Lang;
use Nova\Support\ArrayData;
use Nova\View;
use Symfony\Component\VarDumper\VarDumper;

if (!function_exists('env')) {
	function env(string $key, mixed $default = null)
	{
		static $env = null;

		if ($env === null) {
			$env = @parse_ini_file(APP_ROOT . '/.env', true, INI_SCANNER_TYPED) ?: [];
		}

		return $env[$key] ?? $default;
	}
}

if (!function_exists('conf')) {
	function conf(string $key, mixed $default = null)
	{
		return App::conf($key, $default);
	}
}

if (!function_exists('set_conf')) {
	function set_conf(string $key, mixed $value): void
	{
		App::setConf($key, $value);
	}
}

if (!function_exists('auth')) {
	function auth(string $key = '')
	{
		if ($key) {
			return AuthService::make()->get($key);
		}
		return AuthService::make()->get();
	}
}

if (!function_exists('request')) {
	function request(string|array|true $key = true, $default = '')
	{
		if ($key === true) {
			return Request::make();
		}

		return Request::make()->get($key, $default);
	}
}

if (!function_exists('response')) {
	function response($content = null, $status = 200, array $headers = []): Response
	{
		return Response::make($content, $status, $headers);
	}
}

if (!function_exists('view')) {
	function view($tpl = null, $layout = ''): View
	{
		$view = View::make();

		if ($tpl) {
			$view->body($tpl);
		}
		if ($layout) {
			$view->layout($layout);
		}

		return $view;
	}
}

if (!function_exists('route')) {
	function route(string|array|ArrayData|bool $path = '', string|array|ArrayData|true $params = true): string
	{
		return request()->route($path, $params);
	}
}

if (!function_exists('redirect')) {
	function redirect(string $url = '', string $message = '')
	{
		// TODO : Need data??
		if ($message) {
			$message = _T($message);
		}
		throw (new RedirectException($message))->redirect($url);
	}
}

if (!function_exists('lang')) {
	function lang(): string
	{
		return Lang::getLang();
	}
}

if (!function_exists('_T')) {
	function _T(string $text, ...$args): string
	{
		if (str_starts_with($text, 'LANG:')) {
			$text = substr($text, 5);
			if (Lang::getLang() !== 'ko') {
				$text = Lang::get($text) ?: $text;
			}
		}

		// apply arguments
		if ($args) {
			foreach ($args as $k => $v) {
				$text = str_replace('{' . ($k + 1) . '}', $v, $text);
			}
		}
		return $text;
	}
}

if (!function_exists('blank')) {
	function blank($value): bool
	{
		return is_scalar($value) ? (string)$value === '' : !$value;
	}
}

if (!function_exists('debug')) {
	function debug(mixed $msg, string $label = '', mixed $extra = null): void
	{
		App::debug($msg, $label, $extra);
	}
}

if (!function_exists('error')) {
	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	function error(mixed $message, string $label = ''): void
	{
		if ($message) {
			$message = _T($message);
		}
		throw (new ErrorException($message))->label($label);
	}
}

if (!function_exists('dump')) {
	function dump(...$vars): void
	{
		if (!env('APP_DEBUG')) {
			return;
		}

		$tmp = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$trace = ($tmp[0]['file'] ?? '') === __FILE__ ? $tmp[1] : $tmp[0];
		$context = sprintf('%s:%d', str_replace(APP_ROOT, '', $trace['file']), $trace['line']);

		if (env('APP_ENV') !== 'dev') {
			ob_start();
			var_dump(...$vars);
			echo preg_replace('/' . preg_quote(__FILE__, '/') . ':\d+/', $context, ob_get_clean());
			return;
		}

		// @see vendor/symfony/var-dumper/Resources/functions/dump.php
		if (!$vars) {
			VarDumper::dump('😵‍💫', $context);
			return;
		}
		if (array_key_exists(0, $vars) && 1 === count($vars)) {
			VarDumper::dump($vars[0], $context);
		} else {
			foreach ($vars as $k => $v) {
				VarDumper::dump($v, $context . ' ' . $k);
			}
		}
	}
}

if (!function_exists('dd')) {
	function dd(...$vars): void
	{
		// @see vendor/symfony/var-dumper/Resources/functions/dump.php
		if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true) && !headers_sent()) {
			header('HTTP/1.1 500 Internal Server Error');
		}

		dump(...$vars);
		exit(1);
	}
}


////////////////////////////////////////////////////////////////////////////////////////////////
/// alias

if (!function_exists('__')) {
	function __(...$args): string
	{
		return _T(...$args);
	}
}

if (!function_exists('config')) {
	function config(...$args)
	{
		return conf(...$args);
	}
}
