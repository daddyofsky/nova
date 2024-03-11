<?php
namespace Nova\Routing;

use Nova\App;
use Nova\Http\FormRequest;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Support\Reflection;
use Nova\View;
use RuntimeException;

class Route
{
	protected string $uri;
	protected array  $args       = [];
	protected array  $middleware = [];
	protected mixed  $action     = null;

	public function __construct(string $uri, array $args = [])
	{
		$this->uri = $uri;
		if ($args) {
			$this->args = $args;
		}
	}

	public function dispatch(Request $request): Response
	{
		$request->setArgs($this->getArgs());
		
		// middleware : boot
		$this->bootMiddleware($request);

		$action = $this->getAction();
		if (is_array($action)) {
			[$class, $method] = $action;
			$method || $method = 'index';
			if (!class_exists($class) || !method_exists($class, $method)) {
				response()->error404();
			}
			$response = App::make($class)?->$method(...Reflection::bindParameters([$class, $method], $request->args())) ?? '';
		} elseif (is_callable($action)) {
			$response = $action(...Reflection::bindParameters($action, $request->args()));
		} else {
			response()->error404();
		}

		if (!$response instanceof Response) {
			$response = response()->content($response);
		}

		// middleware : terminate
		$this->terminateMiddleware($request, $response);

		return $response;
	}

	public function setAction($action): static
	{
		$this->action = $action;

		return $this;
	}

	public function getAction()
	{
		return $this->action;
	}

	public function getArgs(): array
	{
		return $this->args;
	}
	
	public function setMiddleware(array $middleware): static
	{
		if ($this->middleware) {
			// prepend
			$this->middleware = array_merge($middleware, $this->middleware);
		} else {
			$this->middleware = $middleware;
		}

		return $this;
	}

	public function bootMiddleware(Request $request): void
	{
		foreach ($this->middleware as $class) {
			$closure = [$class, 'boot'];
			if (is_callable($closure)) {
				$closure($request);
			}
		}
	}

	public function terminateMiddleware(Request|FormRequest $request, Response $response): void
	{
		foreach (array_reverse($this->middleware) as $class) {
			$closure = [$class, 'terminate'];
			if (is_callable($closure)) {
				$closure($request, $response);
			}
		}
	}
}
