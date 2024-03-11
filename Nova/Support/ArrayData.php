<?php
namespace Nova\Support;

use ArrayObject;

class ArrayData extends ArrayObject
{
	public function __construct(array|ArrayObject $data = [])
	{
		parent::__construct($data, ArrayObject::ARRAY_AS_PROPS);
	}

	public function __call(string $method, array $args)
	{
		return $this->offsetGet(0)?->$method(...$args);
	}

	public function merge(iterable $array): static
	{
		foreach ($array as $key => $value) {
			$this[$key] = $value;
		}

		return $this;
	}

	public function only(array|string $keys): static
	{
		$keys = is_array($keys) ? $keys : func_get_args();
		foreach ($this as $key => $value) {
			if (!in_array($key, $keys)) {
				unset($this[$key]);
			}
		}

		return $this;
	}

	public function except(array|string $keys): static
	{
		$keys = is_array($keys) ? $keys : func_get_args();
		foreach ($keys as $key) {
			unset($this[$key]);
		}

		return $this;
	}

	public function all(): array
	{
		return (array)$this;
	}

	public function toArray(): array
	{
		return (array)$this;
	}

}
