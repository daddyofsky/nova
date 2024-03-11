<?php
namespace Nova;

use Nova\Http\Request;
use Nova\Routing\Router;

/**
 * @method static static prefix($uri)
 * @method static static resource(string $uri, string $class)
 * @method static static legacy(string $uri, string $class)
 * @method static static group(array|callable $callback)
 * @method static static get($uri, $action = null)
 * @method static static post($uri, $action = null)
 * @method static static put($uri, $action = null)
 * @method static static patch($uri, $action = null)
 * @method static static delete($uri, $action = null)
 * @method static static options($uri, $action = null)
 * @method static static any($uri, $action = null)
 * @method static static middleware(array|string $middleware)
 * @method static Routing\Route|null getMatchedRoute(string $target, Request $request)
 * @method static array getPrefixParameters()
 */
class Route
{
	public static function __callStatic($method, $args)
	{
		return App::make(Router::class)->$method(...$args);
	}
}
