<?php
namespace Nova\Exceptions;

use Error;
use Nova\App;
use Nova\Debug;
use Nova\DebugError;
use Nova\Http\Response;
use Nova\Log;
use Throwable;

class Handler
{
	protected bool       $legacy;
	protected static int $errorReporting = E_ALL & ~E_NOTICE & ~E_WARNING & ~E_STRICT & ~E_DEPRECATED;

	public function __construct()
	{
		$this->legacy = conf('app.legacy', false);
	}

	public static function errorHandler(int $errNo, string $errMsg, string $file, int $line): void
	{
		if ((PHP_MAJOR_VERSION >= 8 && $errNo === E_WARNING) || !(static::$errorReporting & $errNo)) {
			return;
		}

		if ($errNo & (E_DEPRECATED | E_WARNING | E_USER_NOTICE)) {
			if (conf('app.debug') && !conf('ajax.use')) {
				Debug::output($errMsg);
			}
			return;
		}

		if ($errNo & E_USER_ERROR) {
			static::exceptionHandler(new ErrorException($errMsg, $errNo));
		} else {
			static::exceptionHandler(new PHPErrorException($errMsg, $errNo));
		}
	}

	public static function exceptionHandler(Throwable $e): void
	{
		App::make(static::class)->render($e);
		App::make()->terminate();
	}

	protected function render(Throwable $e): void
	{
		if ($e instanceof ErrorException || $e instanceof Error) {
			// log
			if (!$e instanceof RedirectException) {
				Log::save($e);
			}

			if (conf('app.debug') && conf('app.env') === 'dev') {
				$this->renderErrorDebug($e);
			} else {
				$this->renderError($e);
			}
		} else {
			debug($e, get_class($e));
		}
	}

	protected function renderErrorDebug(ErrorException|Error $e): Response
	{
		ob_start();
		DebugError::make()->handleException($e);
		return response()->content(ob_get_clean());
	}

	protected function renderError(ErrorException|Error $e): Response
	{
		$message = _T($e->getMessage());
		if ($this->legacy && $message) {
			if ($label = $e->getLabel()) {
				$message = sprintf('[%s] %s', $label, $message);	
			}
			return response()
				->content(
					view($this->getErrorTpl($e), 'blank')
						->with('url', $e->getUrl())
						->with('message', $message)
				)
				->with($e->getData());
		}

		return response()
			->redirect($e->getUrl())
			->with('message', $message)
			->with($e->getData());
	}

	protected function getErrorTpl(Throwable $e): array
	{
		$tpl = [];
		if ($e instanceof HttpErrorException) {
			$tpl[] = 'errors.' . $e->getCode();
			$tpl[] = 'errors.500';
			$tpl[] = dirname(__DIR__) . '/views/errors/' . $e->getCode() . '.html';
			$tpl[] = dirname(__DIR__) . '/views/errors/500.html';
			return $tpl;
		}
		
		$tpl[] = 'errors.error';
		$tpl[] = dirname(__DIR__) . '/views/errors/error.html';
		return $tpl;
	}
}
