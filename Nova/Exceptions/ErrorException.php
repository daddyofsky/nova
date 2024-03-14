<?php
namespace Nova\Exceptions;

use ArrayObject;
use Exception;
use Throwable;

class ErrorException extends Exception
{
	protected string $label = '';
	protected string $url   = '';
	protected array  $data  = [];

	public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);

		$this->url = route();
	}

	public function getLabel(): string
	{
		return _T($this->label);
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function getData(): array
	{
		return ['message' => $this->message] + $this->data;
	}

	public function label(string $label): static
	{
		$this->label = $label;
		
		return $this;
	}

	public function redirect(string $url): static
	{
		$this->url = $url;
		
		return $this;
	}

	public function with(string|array|ArrayObject $key, mixed $value = null): static
	{
		if (is_array($key) || $key instanceof ArrayObject) {
			$this->data += (array)$key;
		} else {
			$this->data += [$key => $value];
		}

		return $this;
	}

	public function __debugInfo(): array
	{
		return ['@see' => __METHOD__ . '()'] + array_filter(get_object_vars($this), function ($key) {
				return $key !== '__escape';
			}, ARRAY_FILTER_USE_KEY);
	}
}
