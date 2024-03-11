<?php
namespace Nova\Traits;

trait ClassHelperTrait
{
	protected bool   $debug = false;
	protected string $error = '';

	public function conf(string $key, mixed $default = null): mixed
	{
		return conf($key, $default);
	}

	public function setDebug(bool $flag = true): static
	{
		$this->debug = $flag;
		return $this;
	}

	public function debug(mixed $msg, string $label = '', ?array $extra = null): void
	{
		if ($this->debug) {
			debug($msg, $label, $extra);
		}
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function error(string $msg = '', string $label = ''): bool|string
	{
		if ($msg) {
			$this->error = $msg;
			error($this->error, $label);
		}

		return $this->error;
	}
}
