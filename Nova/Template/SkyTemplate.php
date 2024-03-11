<?php
namespace Nova\Template;

use ArrayAccess;
use Nova\Exceptions\ErrorException;

/**
 * SkyTemplate v3.2.0
 *
 * Copyright (c) 2003-2024  Seo, Jaehan <daddyofsky@gmail.com>
 */
class SkyTemplate
{
	public const VERSION = 'v3.2.0';
	protected const FUNC_DENY = '/^(print_r|var_dump|var_export|debug_backtrace|debug_\w*|file\w*|fopen|readfile|fpassthru|include(_once)?|require(_once)|copy|unlink|rename|mkdir|rmdir|file_exists|is_file|is_dir)$/';	
	
	protected ?SkyTemplateCompiler $_compiler = null;

	protected string      $compileRoot;
	protected string|bool $compile; // always, dynamic(true or anything), simple, false
	protected bool        $mirror;
	protected int         $compileOffset;
	protected array       $preCompiler;
	protected array       $postCompiler;
	protected string      $namespace;
	protected array       $useClass;
	protected string      $formatter;
	protected bool        $errorSafe;
	protected bool        $safeMode;
	protected string      $funcDeny;

	protected string $_top       = '';
	protected array  $_info      = [];
	protected array  $_data      = [];
	protected array  $_file      = [];
	protected array  $_printed   = [];
	protected array  $_error     = [];

	public function __construct(array $config = [])
	{
		$this->compileRoot   = $config['compileRoot'] ?? '';
		$this->compile       = $config['compile'] ?? true;
		$this->mirror        = $config['mirror'] ?? false;
		$this->compileOffset = $config['compileOffset'] ?? 0;
		$this->preCompiler   = $config['preCompiler'] ?? [];
		$this->postCompiler  = $config['postCompiler'] ?? [];
		$this->namespace     = $config['namespace'] ?? '';
		$this->useClass      = $config['useClass'] ?? [];
		$this->formatter     = $config['formatter'] ?? '';
		$this->errorSafe     = $config['errorSafe'] ?? false;
		$this->safeMode      = $config['safeMode'] ?? false;
		$this->funcDeny      = $config['funcDeny'] ?? static::FUNC_DENY;
	}

	public function __toString(): string
	{
		return $this->fetch($this->_top);
	}

	public function compiler(): SkyTemplateCompiler
	{
		if (!$this->_compiler) {
			$this->_compiler = new SkyTemplateCompiler([
				'compileRoot'  => $this->compileRoot,
				'preCompiler'  => $this->preCompiler,
				'postCompiler' => $this->postCompiler,
				'namespace'    => $this->namespace,
				'useClass'     => $this->useClass,
				'formatter'    => $this->formatter,
				'safeMode'     => $this->safeMode,
				'funcDeny'     => $this->funcDeny,
			]);
		}
		
		return $this->_compiler;
	}

	public function define(string|array $block, string $file = ''): true
	{
		if (is_array($block)) {
			foreach ($block as $name => $v) {
				$this->_info[$name] = $v;
				$this->_top         = $name;
			}
			return true;
		}

		$this->_info[$block] = $file;
		$this->_top          = $block;
		return true;
	}

	public function assign(string|array|object $key, mixed $value = null): void
	{
		if (is_array($key) || $key instanceof ArrayAccess) {
			foreach ((array)$key as $k => $v) {
				if ($v !== null) {
					$this->_data[$k] = $v;
				}
			}
			return;
		}
		if (is_object($key)) {
			foreach (get_object_vars($key) as $k => $v) {
				if ($v !== null) {
					$this->_data[$k] = $v;
				}
			}
			return;
		}

		if ($key) {
			$this->_data[$key] = $value;
		}
	}

	public function unassign(string $key): void
	{
		unset($this->_data[$key]);
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function tprint(string $name = ''): void
	{
		$name || $name = $this->_top;
		empty($this->_info[$name]) && $this->error('Print block is invalid. (' . $name . ')', 'SKY_ERR');

		[$tplFile, $cplFile] = $this->getCompileInfo($this->_info[$name]);
		if (!isset($this->_printed[$name])) {
			$this->compile($tplFile, $cplFile);
			$this->_printed[$name] = $tplFile;
		}

		$this->includeCplFile($name, $cplFile);
	}

	public function fetch(string $name = ''): false|string
	{
		ob_start();
		$this->tprint($name);
		return ob_get_clean();
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function getCompileInfo(string $file)
	{
		if (!isset($this->_file[$file])) {
			if ($tplFile = realpath($file)) {
				if ($this->mirror) {
					$cplFile = str_replace([conf('dir.root'), '\\'], ['', '/'], $tplFile);
					$cplFile = strtr($cplFile, ':/', ';%');
				} else {
					$cplFile = basename($tplFile) . '_' . md5($tplFile);
				}
				$cplFile            = $this->compileRoot . '/' . $cplFile;
				$this->_file[$file] = [$tplFile, $cplFile];
			} else {
				$this->error('Template file not exists. (' . $file . ')', 'SKY_ERR');
			}
		}
		return $this->_file[$file];
	}

	public function compile(string $srcFile, string $tgtFile): bool
	{
		$flag = true;
		if ($this->compile) {
			$timestamp = 0;
			if ($this->compile === 'always' || !file_exists($tgtFile) || ($this->compile !== 'simple' && ($timestamp = @filemtime($srcFile) + $this->compileOffset) !== @filemtime($tgtFile))) {
				try {
					$flag = $this->compiler()->compile($srcFile, $tgtFile, $timestamp);
				} catch (ErrorException $e) {
					if ($this->errorSafe) {
						$this->debug($e->getMessage(), 'SKYC_ERR');
					} else {
						$this->error($e->getMessage(), 'SKYC_ERR');
					}
				}
			}
		}
		return $flag;
	}

	public function includeCplFile(string $name, string $file): void
	{
		$v0 = $this->_data;
		include $file;
	}

	public function getIncludeFile(string $file)
	{
		$file = conf('dir.tpl') . '/' . $file;
		[$tplFile, $cplFile] = $this->getCompileInfo($file);
		if (!in_array($tplFile, $this->_printed, true)) {
			$this->_printed[] = $tplFile;
			$this->compile($tplFile, $cplFile);
		}
		return $cplFile;
	}

	/**
	 * print block
	 * (used in compiled template file)
	 */
	public function printBlock(string $name, string $file = ''): void
	{
		if ($file) {
			$this->define($name, $file);
		}

		$this->tprint($name);
	}

	public function debug(string $msg, string $label = ''): void
	{
		debug($msg, $label);
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function error(string $msg, string $label = ''): bool
	{
		error($msg, $label);
	}
}
