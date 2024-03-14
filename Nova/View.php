<?php
namespace Nova;

use App\Providers\ViewServiceProvider;
use Nova\Template\SkyTemplate;
use Nova\Traits\SingletonTrait;

class View extends SkyTemplate
{
	use SingletonTrait;

	protected const LAYOUT = 'LAYOUT';
	protected const BODY   = 'BODY';

	protected string $provider = ViewServiceProvider::class;

	protected string $_layout = '';
	protected string $_body   = '';
	protected string $ext     = 'html';

	public function __construct(array $config = [])
	{
		$compile_root = conf('dir.tpl_cache');
		$lang         = lang();
		if ($lang !== 'ko') {
			$compile_root .= '/' . $lang;
		}

		if (method_exists($this->provider, 'config')) {
			$config = [$this->provider, 'config']($config);
		}

		parent::__construct($config + [
				'compileRoot'   => $compile_root,
				'compile'       => self::isDev() ? 'always' : true,
				'mirror'        => self::isDev(),
				'compileOffset' => (int)conf('view.version', 0),
				'preCompiler'   => conf('view.pre_compiler', []),
				'postCompiler'  => conf('view.post_compiler', []),
				'namespace'     => 'App\Services',
				'useClass'      => conf('view.use_class', []),
				'formatter'     => conf('view.formatter', Format::class),
				'errorSafe'     => !self::isDev(),
				'safeMode'      => false,
			]);

		$ext = conf('view.ext');
		if ($ext) {
			$this->ext = $ext;
		}

		// assign request by default
		$this->with(request());
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function layout(?string $ch): static
	{
		if (!$ch) {
			return $this;
		}

		// DEBUG XXX
		$ch = 'default';

		$tmp = explode('.', $ch);
		$this->_layout = conf('dir.tpl') . '/layout/' . implode('/', $tmp) . '.' . $this->ext;
		$this->debug($this->_layout, 'LAYOUT_FILE');

		if (!is_file($this->_layout)) {
			$this->error('LANG:레이아웃 파일이 없습니다.' . '(' . $ch . ')');
		}

		return $this;
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function body(string|array $file): static
	{
		$body = '';
		foreach ((array)$file as $v) {
			if (!str_contains($v, '/')) {
				$v = conf('dir.tpl') . '/' . str_replace('.', '/', $v) . '.' . $this->ext;
			}
			if (is_file($v)) {
				$body = $v;
				$this->debug($body, 'TPL_FILE');
				break;
			}
		}
		if (!$body) {
			$this->error(sprintf("%s (%s)", _T('LANG:템플릿 파일이 없습니다.'), implode(', ', (array)$file)));
		}
		$this->_body = $body;
		$this->define(self::BODY, $this->_body);

		return $this;
	}

	public function block(string $name, string $file, ?array $data = null): static
	{
		if (!str_contains($file, '/')) {
			$file = conf('dir.tpl') . '/' . str_replace('.', '/', $file) . '.' . $this->ext;
		}
		$this->debug($file, 'TPL_FILE');

		$this->define($name, $file);
		if ($data) {
			$this->assign($name, $data);
		}

		return $this;
	}

	public function with($key, $value = null): static
	{
		$this->assign($key, $value);

		return $this;
	}

	public function render(): string
	{
		ob_start();
		$this->display();
		return ob_get_clean();
	}

	public function display(): void
	{
		if ($this->_layout) {
			$this->define(self::LAYOUT, $this->_layout);
		}

		if (method_exists($this->provider, 'render')) {
			[$this->provider, 'render']($this);
		}
		
		$this->tprint();
	}

	public static function isDev(): bool
	{
		return conf('view.dev', false);
	}

	////////////////////////////////////////////////////////////////////////////////////////////////
	// backward compatability

	public function setLayout(...$args): static {return $this->layout(...$args);}
	public function setBody(...$args): static {return $this->body(...$args);}
	public function setBlock(...$args): static {return $this->block(...$args);}
}
