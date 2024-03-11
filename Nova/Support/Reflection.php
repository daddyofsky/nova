<?php
namespace Nova\Support;

use Nova\App;
use Nova\Http\Request;
use Nova\Model;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;

class Reflection
{
	/**
	 * @throws \ReflectionException
	 */
	public static function getParameters($closure): array
	{
		$reflector = is_array($closure) ? new ReflectionMethod(...$closure) : new ReflectionFunction($closure);
		return $reflector->getParameters();
	}
	
	public static function bindParameters(array|callable $closure, array $args): array
	{
		try {
			$parameters = self::getParameters($closure);
		} catch (ReflectionException) {
			return $args;
		}

		$result = [];
		foreach ($parameters as $parameter) {
			$name = $parameter->getName();
			$parameterType = $parameter->getType();
			$type = $parameterType instanceof ReflectionNamedType ? $parameterType->getName() : '';

			if (str_starts_with($type, 'App\\Requests\\')) {
				$result[] = App::make($type, request());
				continue;
			}
			if ($name === 'request' || $type === Request::class) {
				$result[] = request();
				continue;
			}
			
			if (isset($args[$name])) {
				$value = $args[$name];
				unset($args[$name]);
			} else {
				$value = array_shift($args);
			}
			
			if (str_starts_with($type, 'App\\Models\\')) {
				$result[] = Model::make($type, $value);
			} else {
				$result[] = $value;
			}
		}

		return $result;
	}
}
