<?php
namespace Nova\Exceptions\Solutions;

use Nova\Exceptions\ErrorException;
use Spatie\Ignition\Contracts\Solution;

class RedirectSolution implements Solution
{
	protected ErrorException $e;

	public function __construct(ErrorException $e)
	{
		$this->e = $e;
	}

	public function getSolutionTitle(): string
	{
		return 'Redirect';
	}

	public function getSolutionDescription(): string
	{
		return $this->e->getMessage();
	}

	public function getDocumentationLinks(): array
	{
		return [
			'Move Page' => $this->e->getUrl(),
		];
	}
}
