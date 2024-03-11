<?php
namespace Nova;

require_once __DIR__ . '/helpers.php';

class Autoloader
{
	public static function register(): void
	{
		spl_autoload_register([__CLASS__, 'autoload']);

		if (env('APP_ENV') === 'dev' && env('APP_DEBUG')) {
			self::autoloadVendor();
		}
	}

	public static function autoload(string $class): void
	{
		if (class_exists($class, false)) {
			return;
		}

		$file = self::getClassFilePath($class);
		//echo 'Autoload : ' . $file . '<br />';

		if (file_exists($file)) {
			require_once $file;
			return;
		}

		// // TODO : exception
		// if (!str_contains($file, '/App/Models/') && !str_contains($file, '/Board/')) {
		// 	echo sprintf('<strong>ERROR</strong> : Autoloader : %s : %s<br />', $class, $file);
		// 	echo '<xmp>';
		// 	debug_print_backtrace();
		// 	echo '</xmp>';
		//
		// }
	}

	public static function autoloadVendor(): void
	{
		$file = APP_ROOT . '/vendor/autoload.php';
		if (file_exists($file)) {
			require_once $file;
		}
	}

	protected static function getClassFilePath(string $class): string
	{
		// ex) App\Models\User --> App/Models/User.php
		return dirname(__DIR__) . '/' . preg_replace('/\\\\+/', '/', $class) . '.php';
	}
}
