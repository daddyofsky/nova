<?php
namespace Nova\Http;

use JsonException;
use Nova\Exceptions\HttpErrorException;
use Nova\Traits\SingletonTrait;
use Nova\View;

class Response
{
	use SingletonTrait;
	
	protected const JSON_DEBUG_FLAG = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_LINE_TERMINATORS;

	protected array  $headers     = [];
	protected int    $statusCode  = 200;
	protected bool   $secure      = false;
	protected string $version     = '1.0';
	protected string $charset     = 'utf-8';
	protected array  $cookies     = [];
	protected string $redirectUrl = '';
	protected string $message     = '';
	protected array  $errors      = [];
	protected array  $flash       = [];

	protected ?string $content = null;
	protected ?View   $view    = null;
	protected ?array  $data    = null;

	public function __construct(string|array|View|null $content = null, int $status = 200, array $headers = [])
	{
		if ($content !== null) {
			$this->content($content);
		}
		$this->status($status);
		if ($headers) {
			$this->header($headers);
		}
	}

	public function content(string|array|View $content): static
	{
		// remove old contents
		$this->content = null;
		$this->view    = null;
		$this->data    = null;

		if (is_array($content)) {
			$this->data = $content;
		} elseif ($content instanceof View) {
			$this->view = $content;
		} elseif ($content !== '') {
			$this->content = (string)$content;
		}

		return $this;
	}

	public function hasContent(): string
	{
		return (bool)($this->content ?? $this->view ?? $this->data);
	}

	public function redirect(string $url = ''): static
	{
		$this->redirectUrl = request()->route($url);
		
		return $this;
	}

	public function status(int $status): static
	{
		$this->statusCode = $status;
		
		return $this;
	}
	
	public function header(string|array $header): static
	{
		if (is_array($header)) {
			$this->headers = array_merge($this->headers, $header);
		} else {
			$this->headers[] = $header;
		}
		
		return $this;
	}
	
	public function cookie(string $name, mixed $value, int $time = 0, string $path = '/', string $domain = '', bool $httponly = true, bool $secure = false): static
	{
		$this->cookies[] = [$name, $value, $time, $path, $domain, $httponly, $secure];

		return $this;
	}

	public function with(string|array $key, mixed $value = null): static
	{
		if (is_array($key)) {
			$this->flash = array_merge($this->flash, $key);
		} else {
			$this->flash[$key] = is_string($value) ? _T($value) : $value;
		}

		return $this;
	}

	public function send(): void
	{
		if ($this->flash) {
			Session::setFlash($this->flash);
		}

		$this->sendHeader($this->headers);
		$this->sendCookie($this->cookies);

		if ($this->hasContent()) {
			$this->render();
		} elseif ($this->redirectUrl) {
			$this->sendRedirect($this->redirectUrl);
		}
	}

	protected function sendHeader($headers): static
	{
		foreach ($headers as $header) {
			@header($header);
		}

		return $this;
	}

	protected function sendCookie($cookies): static
	{
		if (headers_sent()) {
			$js = '';
			foreach ($cookies as [$name, $value, $time, $path, $domain, $httponly, $secure]) {
				$js = 'document.cookie = "' . $name . '=' . urlencode($value);
				if ($time) {
					$js .= '; expires=" + (new Date(' . $time . '*1000).toGMTString()) + "';
				}
				if ($path) {
					$js .= '; path=' . $path;
				}
				if ($domain) {
					$js .= '; domain=' . $domain;
				}
				$js .= '";';
			}
			echo '<script type="text/javascript">' . $js . '</script>';
		} else {
			foreach ($cookies as [$name, $value, $time, $path, $domain, $httponly, $secure]) {
				setcookie($name, (string)$value, $time, $path, $domain, $secure, $httponly);
			}
		}

		return $this;
	}

	protected function sendRedirect($to): static
	{
		if (headers_sent()) {
			echo sprintf('<meta http-equiv="refresh" content="0;url=%s">', $to);
		} else {
			header('Location: ' . $to);
		}

		return $this;
	}

	public function render(): void
	{
		if ($this->data !== null) {
			echo $this->toJson($this->data);
		} elseif ($this->view !== null) {
			$this->view->display();
		} else {
			echo $this->content ?? '';
		}
	}

	public function toJson(array $data): false|string
	{
		// TODO : debug data

		try {
			$flag = conf('app.debug') ? self::JSON_DEBUG_FLAG : 0;
			return json_encode($data, JSON_THROW_ON_ERROR | $flag);
		} catch (JsonException $e) {
			$this->error500($e->getMessage());
		}
	}

	/**
	 * @throws \Nova\Exceptions\HttpErrorException
	 */
	public function error404($msg = ''): void
	{
		throw new HttpErrorException($msg, 404);
	}

	/**
	 * @throws \Nova\Exceptions\HttpErrorException
	 */
	public function error500($msg = ''): void
	{
		throw new HttpErrorException($msg, 500);
	}
}
