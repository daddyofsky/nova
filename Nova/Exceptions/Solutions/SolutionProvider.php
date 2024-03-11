<?php
namespace Nova\Exceptions\Solutions;

use Nova\Exceptions\ErrorException;
use Spatie\Ignition\Contracts\HasSolutionsForThrowable;
use Throwable;

class SolutionProvider implements HasSolutionsForThrowable
{
	public function canSolve(Throwable $throwable): bool
	{
		if ($throwable instanceof ErrorException) {
			return (bool)$throwable->getUrl();
		}

		// TODO

		return false;
	}

	public function getSolutions(Throwable $throwable): array
	{
		if ($throwable instanceof ErrorException && $throwable->getUrl()) {
			return [
				new RedirectSolution($throwable),
			];
		}

		return [];
	}
}
