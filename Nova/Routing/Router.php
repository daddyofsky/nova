<?php
namespace Nova\Routing;

use App\Providers\RouteServiceProvider;
use Exception;
use Nova\Exceptions\RouteMatchedException;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Support\Str;

class Router
{
	protected ?Route $matchedRoute = null;
	protected ?Request $request = null;

	protected array $prefixStack = [];

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function prefix($uri): static
	{
		$this->breakIfAlreadyMatched();

		$this->prefixStack[] = '/' . trim($uri, '/') ?: '/';

		return $this;
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function group(array|callable $callback): static
	{
		if (!$this->prefixStack) {
			return $this;
		}

		$prefix = $this->getPrefix();
		if (!$this->matchPrefixPath($prefix)) {
			array_pop($this->prefixStack);
			return $this;
		}

		try {
			$callback(...$this->getPrefixParameters());
			array_pop($this->prefixStack);
		} catch (RouteMatchedException) {
			// nothing
		}

		return $this;
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function resource(string $uri, string $class): static
	{
		return $this->prefix($uri)->group(function(...$args) use ($class) {
			if (method_exists($class, 'route')) {
				[$class, 'route'](...$args);
				return;
			}
			
			$this->get('/', [$class, 'index']);
			$this->get('/create', [$class, 'create']);
			$this->post('/create', [$class, 'store']);
			$this->post('/', [$class, 'store']);
			$this->get('/{id}', [$class, 'view']);
			$this->get('/{id}/edit', [$class, 'edit']);
			$this->post('/{id}/edit', [$class, 'update']);
			$this->put('/{id}', [$class, 'update']);
			$this->get('/{id}/delete', [$class, 'delete']);
			$this->post('/{id}/delete', [$class, 'destroy']);
			$this->delete('/{id}', [$class, 'destroy']);
		});
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function legacy(string $uri, string $class): static
	{
		return $this->prefix($uri)->group(function() use ($class) {
			// remove ending Controller
			$class = preg_replace('/Controller$/', '', $class);

			$this->get('/', [$class . 'Controller', 'index']);
			$this->get('/create', [$class . 'CreateController', 'create']);
			$this->post('/create', [$class . 'CreateController', 'store']);
			$this->post('/', [$class . 'CreateController', 'store']);
			$this->get('/{id}', [$class . 'ViewController', 'view']);
			$this->get('/{id}/edit', [$class . 'EditController', 'edit']);
			$this->post('/{id}/edit', [$class . 'EditController', 'update']);
			$this->put('/{id}', [$class . 'EditController', 'update']);
			$this->get('/{id}/delete', [$class . 'DeleteController', 'delete']);
			$this->post('/{id}/delete', [$class . 'DeleteController', 'destroy']);
			$this->delete('/{id}', [$class . 'DeleteController', 'destroy']);
		});
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function get($uri, $action = null): static
	{
		return $this->matchRoute(['GET', 'HEAD'], $uri, $action);
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function post($uri, $action = null): static
	{
		return $this->matchRoute('POST', $uri, $action);
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function put($uri, $action = null): static
	{
		return $this->matchRoute('PUT', $uri, $action);
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function patch($uri, $action = null): static
	{
		return $this->matchRoute('PATCH', $uri, $action);
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function delete($uri, $action = null): static
	{
		return $this->matchRoute('DELETE', $uri, $action);
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function options($uri, $action = null): static
	{
		return $this->matchRoute('OPTIONS', $uri, $action);
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	public function any($uri, $action = null): static
	{
		return $this->matchRoute(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $uri, $action);
	}

	public function middleware(array|string $middleware): static
	{
		$this->matchedRoute?->setMiddleware((array)$middleware);

		return $this;
	}

	/**
	 * @throws \Exception
	 */
	public function getMatchedRoute(string $target, Request $request): ?Route
	{
		if (!$this->matchedRoute) {
			try {
				$this->request = $request;
				include $this->getConfigFileByTarget($target);

				if (!$this->matchedRoute && class_exists(RouteServiceProvider::class)) {
					RouteServiceProvider::route($target);
				}
			} catch (RouteMatchedException) {
				// nothing
			} catch (Exception $e) {
				throw $e;
			}
		}
		
		return $this->matchedRoute;
	}

	public function getPrefixParameters(): array
	{
		$uri = $this->getPrefix();
		$path = $this->request->path();

		$pattern = '/^' . preg_replace('/\\\{[^}]+\\\}/U', '([^\/]+)', preg_quote($uri, '/')) . '/';
		if (!preg_match($pattern, $path, $match)) {
			return [];
		}

		return array_values($this->matchParameters($uri, $match));
	}

	protected function getConfigFileByTarget($target): string
	{
		return conf('dir.root') . '/routes/' . $target . '.php';
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	protected function breakIfAlreadyMatched(): void
	{
		if ($this->matchedRoute) {
			throw new RouteMatchedException();
		}
	}

	/**
	 * @throws \Nova\Exceptions\RouteMatchedException
	 */
	protected function matchRoute(array|string $methods, string $uri, $action): static
	{
		$this->breakIfAlreadyMatched();

		if (!$this->matchMethod($methods)) {
			return $this;
		}

		$uri = $this->getUriWithPrefix($uri);
		if (!$route = $this->getRouteByPath($uri)) {
			return $this;
		}

		$route->setAction($action);
		$this->matchedRoute = $route;

		return $this;
	}

	protected function getPrefix(string $uri = ''): string
	{
		return '/' . trim(implode('/', $this->prefixStack), '/') . ($uri ? '/' . trim($uri, '/') : '');
	}

	protected function getRouteByPath($uri): Route|false
	{
		$path = $this->request->path();
		if ($uri === $path) {
			return new Route($uri);
		}

		if (str_ends_with($uri, '/*')) {
			$pattern = preg_quote(substr($uri, 0, -2), '/') . '\/?';
		} else {
			$pattern = preg_quote($uri, '/') . '$';
		}
		$pattern = '/^' . preg_replace('/\\\{[^}]+\\\}/U', '([^\/]+)', $pattern) . '/';
		if (!preg_match($pattern, $path, $match)) {
			return false;
		}

		$args = $this->matchParameters($uri, $match);

		return new Route($uri, $args);
	}

	protected function matchParameters($uri, $path_match): array
	{
		$args = [];
		preg_match_all('/\{(?<key>\w+)(?:=(?<exp>[^}]+))?}/', $uri, $key_match);
		foreach ($key_match['key'] as $i => $k) {
			$v = $path_match[$i + 1];
			$exp = $key_match['exp'][$i];
			if (!empty($exp) && !preg_match('/^' . $exp . '$/', $v)) {
				// value pattern mismatch
				continue;
			}

			if (preg_match('/^(\w+)/', $v, $match)) {
				$args[$k] = $match[1];
			} else {
				$args[$k] = '';
			}
		}

		return $args;
	}

	protected function matchPrefixPath($uri): bool
	{
		$path = $this->request->path();
		if (str_starts_with($path, $uri)) {
			return true;
		}

		$pattern = '/^' . preg_replace('/\\\{[^}]+\\\}/U', '([^\/]+)', preg_quote($uri, '/')) . '/';
		return preg_match($pattern, $path);
	}

	protected function matchMethod(array|string $methods): bool
	{
		return in_array($this->request->method(), (array)$methods);
	}

	protected function getUriWithPrefix($uri): string
	{
		if ($uri === '/') {
			return $this->getPrefix();
		}

		return rtrim($this->getPrefix(), '/') . '/' . trim($uri, '/');
	}
}
