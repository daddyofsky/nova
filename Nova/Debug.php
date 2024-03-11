<?php
namespace Nova;

use Nova\Support\ArrayData;
use Nova\Support\Str;

/**
 * Debug Class (static)
 *
 * @since 2008-04-25
 */
class Debug
{
	protected static array $debugData     = [];
	protected static array $debugCombined = [];
	protected static array $debugArgs     = [];
	protected static array $debugArgsFull = [];
	protected static int   $index         = -1;
	protected static int   $queryCount    = 0;

	protected static array $ignoreTrace = [
		'trigger_error',
	];

	public static function output(mixed $var, string $label = '', ?array $extra = null): void
	{
		self::$index++;

		if (str_starts_with($label, '>>')) {
			$label = substr($label, 2);
			if (!isset(self::$debugCombined[$label])) {
				self::$debugCombined[$label] = [];
			}

			$trace = [];
			$dir_root  = realpath(conf('dir.root'));
			$tmp = debug_backtrace();
			foreach ($tmp as $v) {
				if (isset($v['class']) && $v['class'] === __CLASS__) {
					continue;
				}
				$v['file'] = str_replace(['\\', $dir_root], ['/', ''], $v['file']);
				$trace[]   = $v;
			}

			$func = isset($trace[1]['type']) ? $trace[1]['class'] . $trace[1]['type'] . $trace[1]['function'] : $trace[1]['function'];
			self::$debugCombined[$label][] = [$var, sprintf('%s() [%s : %s]', $func, $trace[0]['file'], $trace[0]['line'])];
			return;
		}

		if (isset($extra['term']) && stripos($label, 'QUERY') === 0) {
			self::$queryCount++;
			$var = sprintf('[%.2f] %s', $extra['term'] * 1000, preg_replace('/\s+/', ' ', $var));
		}

		if (is_array($var) || is_object($var)) {
			$type = gettype($var);
			if (is_array($var)) {
				$type .= ' : ' . count($var);
			}
		} else {
			$type = '';
		}

		self::$debugData[self::$index] = [
			'time'  => microtime(true),
			'term'  => $extra['term'] ?? 0,
			'label' => $label,
			'type'  => $type,
			'dump'  => self::dump($var),
			'trace' => self::trace(),
			'query' => isset($extra['query_info']) ? self::queryInfo($extra['query_info']) : '',
		];
	}

	public static function dump(mixed $var): string
	{
		if (is_array($var)) {
			$dump = $var ? print_r($var, true) : '';
		} elseif (is_object($var)) {
			$dump = print_r($var, true);
		} elseif (is_bool($var)) {
			$dump = sprintf('bool(%s)', $var ? 'true' : 'false');
		} elseif ($var) {
			$dump = (string)$var;
		} else {
			ob_start();
			var_dump($var);
			$dump = str_replace(sprintf('%s:%d:', __FILE__, __LINE__ - 1), '', strip_tags(ob_get_clean()));
		}
		return $dump;
	}

	public static function trace(): string
	{
		ob_start();

		$dir_root = realpath(conf('dir.root'));
		$trace    = debug_backtrace(false);
		$idx      = count($trace) + 1;
		foreach ($trace as $k => &$v) {
			$idx--;
			$funcCall = isset($v['type']) ? $v['class'] . $v['type'] . $v['function'] : $v['function'];
			if ((isset($v['class']) && $v['class'] === __CLASS__) || in_array($funcCall, self::$ignoreTrace)) {
				unset($trace[$k]);
				continue;
			}

			echo sprintf('#%d  ', $idx);

			echo $funcCall . ' <b>(</b> ';
			if ($idx === 1) {
				echo '...';
			} elseif (isset($v['args'])) {
				$args = print_r($v['args'], true);
				$id   = crc32($args);
				if (!isset(self::$debugArgs[$id])) {
					$tmp = [];
					foreach ((array)$v['args'] as $arg) {
						$tmp[] = htmlspecialchars(trim(substr((string)preg_replace('/\s+/', ' ', print_r($arg, true)), 0, 30)));
					}
					self::$debugArgs[$id]     = implode(' , ', $tmp);
					self::$debugArgsFull[$id] = $args;
				}
				echo '<a class="_debug_args" data-id="' . $id . '" href="#">' . self::$debugArgs[$id] . '</a>';
			}
			echo ' <b>)</b>';

			if (isset($v['file'])) {
				$v['file'] = str_replace(['\\', $dir_root], ['/', ''], $v['file']);
				echo sprintf(' <span>[ %s : <b>%d</b> ]</span>', $v['file'], $v['line'] . '</b>');
			}
			echo "\n";
		}
		unset($v);

		return rtrim(ob_get_clean());
	}

	public static function queryInfo(array|string $info): string
	{
		ob_start();
		if (is_array($info)) {
			$table = [];
			foreach ($info as $v) {
				if (!$table) {
					$table[] = sprintf('<tr><th>%s</th></th>', implode('</th><th>', array_keys($v)));
				}
				$v['table'] = htmlspecialchars((string)$v['table']);
				$v['type']  = sprintf('<b style="color:%s;">%s</b>', $v['type'] === 'ALL' ? 'red' : 'blue', $v['type']);
				$v['key']   = sprintf('<b style="color:green;">%s</b>', $v['key']);
				$v['Extra'] = preg_replace(['/(Using (index))/i', '/(Using (filesort|temporary))/i'], [
					'<b style="color:blue;">$1</b>',
					'<b style="color:red;">$1</b>',
				], (string)$v['Extra']);
				$table[]    = '<tr><td>' . implode('</td><td>', array_values($v)) . '</td></tr>';
			}
			echo '<table>' . implode('', $table) . '</table>';
		} else {
			echo '<b style="color:red;">' . $info . '</b>';
		}
		return ob_get_clean();
	}

	/**
	 * @throws \JsonException
	 */
	public static function toJson(array|ArrayData $data): false|string
	{
		return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_LINE_TERMINATORS);
	}

	public static function getDebugDataForJson(): array
	{
		foreach (self::$debugData as &$v) {
			if ($v['label']) {
				$v['dump'] = $v['label'] . ' : ' . $v['dump'];
			}
			$v['dump'] = Str::cut($v['dump'], 200);
			$v['trace'] = explode("\n", strip_tags($v['trace']));
			unset($v['time'], $v['term'], $v['label'], $v['type'], $v['query']);
		}

		return self::$debugData;
	}

	/**
	 * debug output handler
	 * output debug message after all process ended
	 *
	 * @see App::terminate()
	 */
	public static function debugOutputHandler(): void
	{
		if (!conf('app.debug') || conf('ajax.use')) {
			return;
		}

		// combined debug info
		if (self::$debugCombined) {
			foreach (self::$debugCombined as $key => $val) {
				self::output($val, $key);
			}
		}

		self::$debugArgs = [];

		if (!headers_sent()) {
			@header('Content-type: text/html; charset=utf-8');
		}

		// delimiter
		echo str_repeat("\n", 20);
		echo '<!-- DEBUG OUTPUT ' . str_repeat('-', 70) . '>';
		echo '<div style="clear:both;"></div>';
		echo str_repeat("\n", 20);

		// header : css, js
		self::printDebugHeader();

		echo "\n";
		echo '<div id="_debug_wrap" class="' . ($_COOKIE['DO'] ? 'on' : '') . '">';

		// header
		echo '<div class="_debug_header">';
		echo '<h2 id="_debug_title">Debug</h2>';
		echo '<div class="_debug_anchor">';
		echo '<a href="#_debug_wrap" accesskey="1">Debug</a>';
		echo '<a href="#_debug_query_list" accesskey="2">Query</a>';
		echo '<a href="#_debug_files" accesskey="3">File</a>';
		echo '<a href="#top" accesskey="4">Top</a>';
		echo '</div>';
		echo '</div>';
		echo "\n";

		// timeline
		echo "\n";
		echo '<div class="_debug_timeline">';
		self::printTimeline();
		echo '</div>';

		// body
		echo '<div id="_debug_body">';

		// print debug output
		echo "\n";
		echo '<div id="_debug_output">';
		foreach (self::$debugData as $index => $value) {
			self::printDebugData($index, $value);
		}
		echo '</div>';

		// print debug query
		self::printQueryList();

		// print included files
		self::printIncludedFiles();

		echo '</div>';
		echo '</div>';

		// tip layer
		echo '<div id="_debug_tooltip" style="display:none;"></div>';

		// args full
		echo "\n\n\n<!-- debug args -->";
		foreach (self::$debugArgsFull as $id => $args) {
			echo "\n";
			echo '<pre id="_debug_args_' . $id . '" style="display:none;">' . htmlspecialchars($args) . '</pre>';
		}
	}

	public static function printTimeline(): void
	{
		$timestamp = App::getTimes();

		$start  = reset($timestamp);
		$total  = end($timestamp) - $start;
		if (!$total) {
			return;
		}

		$from   = 0;
		$name   = '';
		foreach ($timestamp as $k => $v) {
			if ($from) {
				$term    = $v - $from;
				$left    = round(100 * ($from - $start) / $total, 1);
				$percent = round(100 * $term / $total, 1);
				echo sprintf('<div class="_debug_part" style="left:%s%%; width:%s%%;" data-title="%s : %s (%s%%)"></div>', $left, $percent, $name, round($term, 5), $percent);
			}
			$from = $v;
			$name = $k;
		}

		foreach (self::$debugData as $index => $value) {
			if (!$value['term']) {
				continue;
			}
			$term    = $value['time'];
			$left    = round(100 * ($term - $start) / $total, 1);
			$percent = round(100 * $value['term'] / $total, 1);
			echo sprintf('<a href="#_debug_row_%s" class="_debug_time_%s" style="left:%s%%; width:%s%%;" data-title="[%d] %s"></a>', $index, $index, $left, $percent, $index, htmlspecialchars(($value['label'] ? $value['label'] . ' : ' : '') . $value['dump']));
		}
	}

	public static function printDebugData($index, $value): void
	{
		echo "\n\n\n";
		echo '<div id="_debug_row_' . $index . '" class="_debug_row">';

		// header
		echo "\n";
		echo '<div class="_debug_brief">';
		echo '<b class="_debug_index">[' . $index . ']</b> ';
		if ($value['label']) {
			echo '<span class="_debug_label">' . $value['label'] . '</span> : ';
		}
		if ($value['type']) {
			echo '<button class="_debug_type" type="button">' . $value['type'] . '</button>';
		} else {
			echo '<span class="_debug_text">' . htmlspecialchars($value['dump']) . '</span>';
		}
		echo '</div>';

		// query info
		if ($value['query']) {
			echo "\n";
			echo '<div class="_debug_query_info">';
			echo $value['query'];
			echo '</div>';
		}

		// array or object
		if ($value['type'] && $value['dump']) {
			echo "\n";
			echo '<pre class="_debug_data" style="display:none;">';
			echo htmlspecialchars($value['dump']);
			echo '</pre>';
		}

		// trace
		echo "\n";
		echo '<div class="_debug_trace">';
		if (str_contains($value['trace'], "\n")) {
			echo '<button type="button" class="_debug_trace_toggle"></button>';
		} else {
			echo '<div class="_debug_trace_single"></div>';
		}
		echo "\n";
		echo '<pre class="_debug_trace_data">';
		echo $value['trace'];
		echo '</pre>';
		echo '</div>';

		echo '</div>';
	}

	public static function printQueryList(): void
	{
		echo "\n\n\n<!-- debug query list -->\n";
		echo '<div id="_debug_query_list" class="_debug_extra">';
		echo "\n";
		echo '<h3 class="_debug_subtitle">Query : ' . self::$queryCount . '</h3>';
		echo "\n";
		echo '<pre class="_debug_pre">';
		foreach (self::$debugData as $index => $value) {
			if (!$value['query']) {
				continue;
			}
			echo sprintf('<b class="_debug_index">[%s]</b> <a href="#_debug_row_%s">%s</a>', $index, $index, htmlspecialchars($value['dump']));
			echo "\n";
		}
		echo '</pre>';
		echo '</div>';
	}

	public static function printIncludedFiles(): void
	{
		$files    = get_included_files();
		$dir_root = realpath(conf('dir.root'));

		echo "\n\n\n<!-- debug file list -->\n";
		echo '<div id="_debug_files" class="_debug_extra">';
		echo "\n";
		echo '<h3 class="_debug_subtitle">File : ' . count($files) . '</h3>';
		echo "\n";
		echo '<pre class="_debug_pre">';
		foreach ($files as $key => $file) {
			$file = str_replace(['\\', $dir_root], ['/', ''], $file);
			echo sprintf('<b class="_debug_index">[%s]</b> %s', $key, $file);
			echo "\n";
		}
		echo '</pre>';
		echo '</div>';
	}

	protected static function printDebugHeader(): void
	{
		$header = <<<HEADER
<style>
	#_debug_wrap { position:relative; z-index:99999; font-family:sans-serif; }
	#_debug_wrap * { box-sizing:border-box !important; letter-spacing:0 !important; }
	#_debug_body { all:revert; display:none; margin:0; padding:10px; background-color:#fff; }
	#_debug_wrap.on #_debug_body { display:block; }
	._debug_header { all:revert; position:fixed; left:0; bottom:0; display:flex; align-items:center; width:100px; height:40px; margin:0; padding:10px; overflow:hidden; cursor:pointer; opacity:0.5; background-color:#77f; }
	._debug_header:hover { opacity:1; }
	#_debug_wrap.on ._debug_header { position:sticky; top:0; width:auto; opacity:1; z-index:1; }
	._debug_header h2 { all:revert; display:block; width:100px; margin:0; padding:0; text-align:center; color:#fff; font-size:20px; line-height:20px; font-weight:700; }
	#_debug_wrap.on h2 { width:auto; text-align:left; }
	._debug_timeline { all:revert; position:sticky; display:none; top:40px; height:12px; overflow:hidden; }
	#_debug_wrap.on ._debug_timeline { display:block; z-index:1; }
	._debug_timeline > * { all:revert; position:absolute; display:block; height:12px; min-width:1px; }
	._debug_timeline ._debug_part:nth-child(4n+1) { border:1px solid #8fd5ea; background-color:#afe1f0; }
	._debug_timeline ._debug_part:nth-child(4n+2) { border:1px solid #a7d85a; background-color:#dff9b5; }
	._debug_timeline ._debug_part:nth-child(4n+3) { border:1px solid #fbcfa0; background-color:#feff77; }
	._debug_timeline ._debug_part:nth-child(4n) { border:1px solid #f89fbc; background-color:#fedfe5; }
	._debug_timeline > a { border:1px solid #f90915; border-radius:3px; background-color:#ff5303; }
	._debug_timeline > a.on, ._debug_timeline > a:hover { background-color:#f90915; cursor:pointer; }
	._debug_anchor { all:revert; display:none; margin-left:auto; }
	#_debug_wrap.on ._debug_anchor { display:block; }
	._debug_anchor a { all:revert; display:inline-block; margin:0; padding:0 5px; color:#fff; font-size:14px; text-decoration:none; }
	._debug_anchor a:hover { color:yellow; }
	._debug_row { all:revert; margin:10px 0 15px 0; padding:0 10px; }
	._debug_row * { all:revert; font-size:14px; line-height:16px; }
	._debug_brief { all:revert; padding:5px 10px; font-size:14px; background-color:#e6e6e6; cursor:pointer; }
	._debug_brief:hover { background-color:#cdf; }
	._debug_row.on ._debug_brief { border:3px solid #5d6edd; }
	._debug_index { all:revert; margin:0 5px 0 0; color:blue; font-size:14px; }
	._debug_label { all:revert; color:red; font-size:14px; }
	._debug_type { all:revert; height:20px; padding:0 3px; border:1px solid #333; border-radius:2px; color:#333; background-color:#eee; font-size:14px; line-height:18px; }
	._debug_text { all:revert; color:#333; font-weight:normal; font-size:14px; }
	._debug_data { all:revert; margin:10px 10px 10px 30px; padding:10px; max-height:800px; overflow-y:auto; font-size:14px; font-family:monospace; border:1px solid #333; background-color:#eef; }
	._debug_trace { all:revert; margin:10px 10px 10px 30px; padding:0; }
	._debug_trace_single { all:revert; float:left; margin:0 15px 0 0; padding:0; width:26px; height:20px; visibility:hidden; }
	._debug_trace_toggle { all:revert; float:left; margin:0 15px 0 0; padding:0; width:26px; height:20px; border:1px solid #ccc; border-radius:2px; background:none; cursor:pointer; font-size:12px; }
	._debug_trace_toggle:before { content:"+"; }
	._debug_trace_toggle.on:before { content:"-" !important; }
	._debug_trace_data { all:revert; margin:0; padding:2px; height:19px; overflow:hidden; font-size:14px; }
	._debug_trace_data a { all:revert; color:#888; }
	._debug_trace_data a:hover { color:#41a0ff; }
	._debug_trace_toggle.on + ._debug_trace_data { height:auto; overflow:auto; color:#333; }
	._debug_query_info { all:revert; margin:10px 10px 10px 30px; padding:0; overflow:auto; font-size:14px; }
	._debug_query_info table { all:revert; margin:0; border-collapse:collapse; border:1px solid #333; }
	._debug_query_info th, ._debug_query_info td { all:revert; padding:5px; color:#555; font-size:14px; font-weight:normal; border:1px solid #333; }
	._debug_extra { all:revert; margin:10px; }
	._debug_extra ._debug_subtitle { all:revert; margin:5px 0; padding:8px; color:#fff; background-color:#999; font-size:14px; line-height:14px; }
	._debug_extra ._debug_pre { all:revert; margin:0; padding:10px; overflow:auto; color:#777; font-size:14px; }
	._debug_extra ._debug_pre a { all:revert; color:#777; }
	._debug_extra ._debug_pre a:hover { color:#41a0ff; }
	#_debug_tooltip { all:revert; position:absolute; z-index:99999; width:auto; padding:5px; border:1px solid #333; background-color:#ffd; font-family:sans-serif; }
</style>
<script type="text/javascript">
window.addEventListener('DOMContentLoaded', function () {
	function q(selector) {
		return typeof selector === 'string' ? document.querySelector(selector) : selector;
	}
	function qa(selector) {
		return document.querySelectorAll(selector);
	}
	function l(el, type, listener) {
		qa(el).forEach(function (el) {
			el.addEventListener(type, listener);
		});
	}
	function s(evt) {
		evt.preventDefault();
		evt.stopPropagation();
	}
	function toggle(el, force) {
		el = q(el);
		if (el && typeof el === 'object') {
			el.style.display = (force || el.style.display === 'none') ? 'block' : 'none';
		}
	}
	function onoff(force) {
		q('#_debug_wrap').classList.toggle('on', force);
		document.cookie = 'DO=' + (q('#_debug_wrap').classList.contains('on') ? 'on' : '') + '; path=/';
	}
	function pin(i, force) {
		q('#_debug_row_' + i).classList.toggle('on', force);
		const t = q('._debug_time_' + i);
		if (t) {
			t.classList.toggle('on', force);					
		}
	}
	function fixScroll(amount) {
		try {
			const p = document.scrollingElement || document.documentElement || document.body;
			p.scrollBy(0, amount || -60);
		} catch (e) {
			console.log(e);
		}
	}
	function tip(e, text) {
		const layer = q('#_debug_tooltip');
		const width = Math.min(text.length * 8, window.innerWidth * 0.8);
		layer.style.left = Math.min(Math.max((e.pageX - width / 2), 0), window.innerWidth - width - 20) + 'px';
		layer.style.top = (e.pageY + 10) + 'px';
		layer.style.width = width + 'px'; 
		layer.innerHTML = text;
		toggle(layer, true);
	}
	function tipx() {
		toggle('#_debug_tooltip');
	}
	l('._debug_header', 'click', function (e) {
		s(e);
		onoff();
		q('#_debug_wrap').scrollIntoView();
	});
	l('._debug_anchor > a', 'click', function (e) {
		s(e);
		const selector = this.href.substr(this.href.lastIndexOf('#'));
		if (selector === '#top') {
			document.body.scrollIntoView();
		} else {
			onoff(true);
			q(selector).scrollIntoView();
			if (selector !== '#_debug_wrap') {
				fixScroll();
			}
		}
	});
	l('._debug_brief', 'click', function (e) {
		s(e);
		const id = this.parentNode.getAttribute('id');
		if (id) {
			pin(id.match(/_([\dA-Z]+)$/).pop());
		}
	});
	l('._debug_trace_toggle', 'click', function (e) {
		s(e);
		this.classList.toggle('on');
	});
	l('._debug_type', 'click', function (e) {
		s(e);
		toggle(this.parentNode.parentNode.querySelector('._debug_data'));
	});
	l('._debug_args', 'click', function (e) {
		s(e);
		const args = q('#_debug_args_' + this.getAttribute('data-id')).innerText;
		console.log(args);
		alert(args);
	});
	l('#_debug_query_list a, ._debug_timeline a', 'click', function (e) {
		s(e);
		onoff(true);
		const m = this.href.match(/#_debug_row_(\d+)$/);
		const el = q(m[0]);
		el.scrollIntoView();
		pin(m[1], true);
		fixScroll(-62);
	});
	l('#_debug_wrap *[data-title]', 'mouseover', function(e) {
		tip(e, this.getAttribute('data-title'));
	});
	l('#_debug_wrap *[data-title]', 'mouseout', function() {
		tipx();
	});
});
</script>
HEADER;
		echo preg_replace('/\h*\R+\h*/', ' ', $header);
	}
}
