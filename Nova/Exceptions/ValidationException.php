<?php
namespace Nova\Exceptions;

class ValidationException extends ErrorException
{
	public function errors(array $errors): static
	{
		$this->message = $this->summarize($errors);
		return $this->with('errors', $errors)->with(request()->post());
	}

	protected function summarize(array $errors)
	{
		$message = reset($errors)[0];
		$count   = count($errors);
		if ($count > 1) {
			$message .= sprintf(' (%d errors)', $count);
		}

		return $message;
	}
}
