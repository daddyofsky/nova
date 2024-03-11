<?php
namespace Nova\Support;

use Nova\App;
use RuntimeException;

abstract class Facade
{
	public static function __callStatic($method, $args)
	{
		$accessors = (array)static::getFacadeAccessors();
		if (!$accessors) {
			throw new RuntimeException('No Facade accessor.');
		}

		foreach ($accessors as $accessor) {
			if (method_exists($accessor, $method)) {
				return App::make($accessor)->$method(...$args);
			}
		}

		throw new RuntimeException('Facade method not exists.');
	}

	public function __call($method, $args)
	{
		return self::__callStatic($method, $args);
	}

	protected static function getFacadeAccessors(): array|string
	{
		// for override
	}
}
