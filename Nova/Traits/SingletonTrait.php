<?php
namespace Nova\Traits;

use Nova\App;

trait SingletonTrait
{
	public static function __callStatic($method, $args)
	{
		return static::make()->$method(...$args);
	}

	public static function make(...$args)
	{
		return App::make(static::class, ...$args);
	}
}
