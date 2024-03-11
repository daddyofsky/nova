<?php
namespace Nova;

use Nova\Exceptions\Solutions\SolutionProvider;
use Spatie\FlareClient\Report;
use Spatie\Ignition\Ignition;
use Throwable;

class DebugError extends Ignition
{
	protected static ?DebugError $instance = null;

	public function __construct()
	{
		parent::__construct();

		$this->applicationPath(APP_ROOT)
			->addSolutionProviders([
				SolutionProvider::class,
			]);
	}

	public static function make(): static
	{
		if (static::$instance) {
			return static::$instance;
		}

		// for .ignition.json
		$app_root = str_replace('\\', '/', realpath(APP_ROOT));
		$_SERVER['HOMEDRIVE'] = substr($app_root, 0, strpos($app_root, '/') + 1);
		$_SERVER['HOMEPATH']  = substr($app_root, strlen($_SERVER['HOMEDRIVE']));

		return static::$instance = (new static());
	}

	public function register(): static
	{
		set_exception_handler([$this, 'handleException']);

		return $this;
	}

	public function handleException(Throwable $throwable): Report
	{
		$this->addCustomHtml();

		[$remote, $local] = explode(':', env('APP_LOCAL_PATH', ''));
		if ($local) {
			ob_start();
			$r = parent::handleException($throwable);
			echo str_replace(
				[$remote, str_replace('/', '\\/', $remote)],
				[$local, str_replace('/', '\\/', $local)],
				ob_get_clean()
			);
			return $r;
		}

		return parent::handleException($throwable);
	}

	protected function addCustomHtml(): static
	{
		$header = <<<STYLE
			<style>
				#app {
					all:revert;
					position: absolute;
					left:0;
					top:0;
					width:100vw;
					height:100vh;
					z-index:99999;
					background-color:#e4e7eb;
				}
			</style>
			STYLE;

		$body = <<<SCRIPT
			<script type="text/javascript">
				const app = document.getElementById('app');
				app.className = 'antialiased bg-center bg-dots-darker dark:bg-dots-lighter';
				document.body.insertBefore(app, document.body.firstChild);
				
				// fix target for inner url
				const solutionLink = document.getElementById('solution').getElementsByTagName('a');
				for (let a of solutionLink) {
					if (a.href.indexOf(window.location.origin) === 0) {
						a.setAttribute('target', '_self');
					}
				}
			</script>
			SCRIPT;

		$this->addCustomHtmlToHead($header);
		$this->addCustomHtmlToBody($body);

		return $this;
	}
}
