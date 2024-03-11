<?php
namespace Nova;

use Throwable;
use RuntimeException;

class Log
{
	public const LOG_ERROR = 1;
	public const LOG_DEBUG = 2;
	public const LOG_QUERY = 3;

	protected const MAX_FILE_SIZE = 1; // MB
	protected const FILE_EXT      = 'log';
	
	public static function save(mixed $data, int|string $type = self::LOG_ERROR, string $prefix = ''): void
	{
		self::write(
			self::getLogFile($type, $prefix),
			self::getLogMessage($data)
		);
	}

	protected static function getLogMessage(mixed $data): false|string
	{
		ob_start();
		echo "\n" . str_repeat('-', 80) . "\n";
		echo sprintf('[%s] %s %s', date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR'], self::getUserId()) . "\n\n";

		self::dump($data);
		echo "\n\n";

		self::trace($data);
		echo "\n\n";
		
		return ob_get_clean();
	}

	protected static function getUserId()
	{
		return $_SESSION['user_id'] ?? session_id();
	}

	protected static function dump(mixed $data): void
	{
		if (is_bool($data)) {
			echo $data ? '(bool) true' : '(bool) false';
		} elseif (is_scalar($data)) {
			echo $data;
		} elseif ($data instanceof Throwable) {
			echo $data->getMessage();
		} else {
			print_r($data);
		}
	}

	protected static function trace(mixed $data): void
	{
		if ($data instanceof Throwable) {
			$trace = $data->getTrace();
		} else {
			$trace = debug_backtrace();
		}
		
		foreach ($trace as $k => $v) {
			echo '#' . $k . ' ';
			echo $v['file'] ?? '';
			echo isset($v['line']) ? '(' . $v['line'] . ')' : '';
			echo isset($v['file'], $v['line']) ? ': ' : ' ';
			echo isset($v['class']) ? $v['class'] . '::' : '';
			echo $v['function'] ?? '';
			if (isset($v['args'])) {
				$args = [];
				foreach ($v['args'] as $arg) {
					$args[] = preg_replace('/\s+/', ' ', print_r($arg, true));
				}
				echo '(';
				echo implode(', ', $args);
				echo ')';
			}
			echo "\n";
		}
	}

	protected static function getLogFile(int|string $type = self::LOG_ERROR, string $prefix = ''): string
	{
		// ex) log/error/202403/20240309.log
		// ex) log/query/202403/user_20240309.log
		
		$dir = rtrim(conf('dir.log'), '/');
		$dir .= '/' . match ($type) {
			self::LOG_ERROR => 'error',
			self::LOG_DEBUG => 'debug',
			self::LOG_QUERY => 'query',
			default => $type,	
		};
		$dir .= '/' . date('Ym');

		if (!is_dir($dir)) {
			if (!mkdir($dir, 0707, true) && !is_dir($dir)) {
				throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
			}
			@chmod($dir, 0707);
		}

		$base = sprintf('%s/%s%s', $dir, $prefix ? $prefix . '_' : '', date('Ymd'));
		return self::getNumberedLogFile($base);
	}

	protected static function getNumberedLogFile(string $base, string $ext = self::FILE_EXT): string
	{
		$number = 0;
		while (true) {
			$file = $base . '_' . $number . '.' . $ext;
			if (!file_exists($file)) {
				break;
			}
			if (filesize($file) < self::MAX_FILE_SIZE * 1048576) {
				break;
			}
			$number++;
		} 
		
		return $file;
	}

	protected static function write(string $file, string $msg): bool
	{
		if (file_put_contents($file, $msg, FILE_APPEND)) {
			chmod($file, 0606);
			return true;
		}
		return false;
	}
}
