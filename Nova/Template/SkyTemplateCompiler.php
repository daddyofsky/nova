<?php
namespace Nova\Template;

use Error;
use RuntimeException;

/**
 * SkyTemplate2Compiler v3.2.0
 *
 * Copyright (c) 2003-2024  Seo, Jaehan <daddyofsky@gmail.com>
 */
class SkyTemplateCompiler
{
	protected const PATTERN_VAR = '(?<scope>_|\.+|c\.)?(?<ns>\\\?(?:[a-zA-Z]\w*\\\)+)?(?<var_name>[a-zA-Z_]\w*)(?<var_up>@\d*)?(?<var_array>(?:\.\w+)*)?(?<class>(?<class_type>->|::)(?<prop>[a-zA-Z_]\w*)(?<prop_array>(?:\.\w+)*)?)*(?<zerofill>#\d+)?(?<func>(?:\|\w+(?:=.+)?)*)?';
	protected const PATTERN_TAG = '/(?|({\*|\*})|{(\?:|[&?:\/@%=;#+\]\\\]|loop|each|if|foreach|for|while|else|end|refer|include|execute|dump|escape)\h*)/i';

	// protected const PATTERN_VAR = '(?<scope>_|\\.+|c\\.)?(?<ns>\\\\\\?(?:[a-zA-Z]\\w*\\\\\\)+)?(?<var_name>[a-zA-Z_]\\w*)(?<var_up>@\\d*)?(?<var_array>(?:\\.\\w+)*)?(?<class>(?:->|::)(?:[a-zA-Z_]\\w*)(?:\\.\\w+)*?)+(?<zerofill>#\\d+)?(?<func>(?:\\|\\w+(?:=.+)?)*)?';


	protected string $compileRoot  = './_compile';
	protected array  $preCompiler  = [];
	protected array  $postCompiler = [];
	protected string $namespace    = '';
	protected string $formatter    = '';
	protected bool   $safeMode     = false;
	protected string $funcDeny     = '';
	protected string $varDeny      = '';
	protected array  $useClass     = [];

	protected string $error = '';
	protected bool   $debug = false;

	protected array $tagAlias = [
		'\\'          => 'escape',
		'&'           => 'refer',
		'?'           => 'if',
		':'           => 'else',
		'?:'          => 'elvis',
		'/'           => 'end',
		'@'           => 'loop',
		'%'           => 'loop',
		'each'        => 'loop',
		'='           => 'raw',
		';'           => 'php',
		'#'           => 'include',
		'+'           => 'execute',
		']'           => 'dump',
	];

	private string $srcFile   = '';
	private array  $arrBlock  = [];
	private int    $isComment = 0;
	private int    $depth     = 0;

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function __construct(array $config = [])
	{
		$this->compileRoot  = $config['compileRoot'] ?? sys_get_temp_dir() . '/_compile';
		$this->preCompiler  = $config['preCompiler'] ?? [];
		$this->postCompiler = $config['postCompiler'] ?? [];
		$this->namespace    = $config['namespace'] ?? '';
		$this->useClass     = $config['useClass'] ?? [];
		$this->formatter    = $config['formatter'] ?? '';
		$this->safeMode     = $config['safeMode'] ?? false;
		$this->funcDeny     = $config['funcDeny'] ?? '';

		if (!is_dir($this->compileRoot)) {
			$this->makeDir($this->compileRoot) || $this->error('Template cache directory not exists : ' . $this->compileRoot);
		}
		if (!is_writable($this->compileRoot)) {
			$this->error('Template cache directory in not writable : ' . $this->compileRoot);
		}
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function compile(string $srcFile, string $tgtFile, int $timestamp = 0): bool
	{
		if (!file_exists($srcFile)) {
			$this->error('Template file not exists : ' . $srcFile);
		}
		$src = file_get_contents($srcFile);
		if ($src === false) {
			$this->error('Template file read error : ' . $srcFile);
		}
		if (!$src) {
			$this->debug('Template file is empty : ' . $srcFile);
		}

		// prevent php code
		$src = str_replace(['<?', '?>'], ['&lt;?', '?&gt;'], $src);

		$this->srcFile = $srcFile;

		if ($this->preCompiler) {
			$src = $this->applyCompiler($this->preCompiler, $src, $srcFile, $tgtFile);
		}

		$php = $this->parse($src);

		if ($this->postCompiler) {
			$php = $this->applyCompiler($this->postCompiler, $php, $srcFile, $tgtFile);
		}

		if ($this->writeFile($tgtFile, $php)) {
			@touch($tgtFile, $timestamp ?: filemtime($srcFile)/** + filesize($srcFile)*/);
			return true;
		}

		$this->error('Template cache file write error : ' . $tgtFile);
	}

	public function applyCompiler(array $compilers, string $src, string $srcFile, string $tgtFile): string
	{
		foreach ($compilers as $compiler) {
			if (!is_callable($compiler)) {
				continue;
			}
			$src = $compiler($src, $srcFile, $tgtFile);
		}
		return $src;
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	public function parse(string $src): string
	{
		try {
			$this->arrBlock = [];
			$this->isComment = 0;
			$this->depth = 0;

			$php = $this->makeHeader();
			foreach (explode("\n", $src) as $line => $str) {
				$php .= $this->parsePerLine($str) . "\n";
			}
			return $php;

		} catch (RuntimeException $e) {
			$this->error(sprintf('%s : %s[%d]', $e->getMessage(), basename($this->srcFile), ($line ?? 0) + 1));
		}
		return '';
	}

	protected function parsePerLine(string $str): string
	{
		$str    = preg_replace(['/<!--+\{/', '/}--+>/'], ['{', '}'], $str);
		$tokens = preg_split(self::PATTERN_TAG, $str, -1, PREG_SPLIT_DELIM_CAPTURE);

		if ($this->isComment) {
			$php = $tokens[0];
		} else {
			$php = $this->parseVar($tokens[0]);
		}

		for ($i = 1, $ci = count($tokens); $i < $ci; $i += 2) {
			$current = $tokens[$i];
			$next    = $tokens[$i + 1] ?? '';

			// comment
			if ($code = $this->tagComment($current, $next)) {
				$php .= $code;
				continue;
			}

			// no tag close
			if (!str_contains($next, '}')) {
				$php .= $this->parseVar($current . $next);
				continue;
			}

			// template tag
			$tag = strtolower($current);
			$tag = $this->tagAlias[$tag] ?? $tag;

			[$arg, $etc] = explode('}', $next, 2);
			$arg = trim($arg);
			$php .= match ($tag) {
				'refer'   => $this->tagRefer($arg) . $this->parseVar($etc),
				'if'      => $this->tagIf($arg) . $this->parseVar($etc),
				'else'    => $this->tagElse($arg) . $this->parseVar($etc),
				'end'     => $this->tagEnd($arg) . $this->parseVar($etc),
				'elvis'   => $this->tagElvis($arg) . $this->parseVar($etc),
				'loop'    => $this->tagLoop($arg) . $this->parseVar($etc),
				'for'     => $this->tagFor($arg) . $this->parseVar($etc),
				'foreach' => $this->tagForeach($arg) . $this->parseVar($etc),
				'while'   => $this->tagWhile($arg) . $this->parseVar($etc),
				'raw'     => $this->tagRaw($arg) . $this->parseVar($etc),
				'php'     => $this->tagPhp($arg) . $this->parseVar($etc),
				'include' => $this->tagInclude($arg) . $this->parseVar($etc),
				'execute' => $this->tagExecute($arg) . $this->parseVar($etc),
				'dump'    => $this->tagDump($arg) . $this->parseVar($etc),
				'escape'  => '{' . $this->parseVar($next),
				default   => $this->parseVar($current . $next), // invalid tag
			};
		}

		return $php;
	}

	protected function makeHeader(): string
	{
		return '<?php /** SkyTemplate2 ' . date('Y-m-d H:i:s') . ' */ '
		       . ($this->namespace ? 'namespace ' . $this->namespace . '; ' : '')
		       . ($this->formatter ? 'use ' . $this->formatter . ' as _F; ' : '')
		       . ($this->useClass ? 'use ' . implode('; use ', $this->useClass) . '; ' : '')
		       . '?>';
	}

	protected function tagComment(string $current, string $next): string
	{
		if ($current === '{*') {
			$this->isComment++;
			if ($this->isComment === 1) {
				return '<?php /*' . $next;
			}
		} elseif ($current === '*}') {
			$this->isComment--;
			if ($this->isComment === 0) {
				return '*/ ?>' . $this->parseVar($next);
			}
		}

		if ($this->isComment) {
			return $current . $next;
		}

		// not in comment
		return '';
	}

	protected function tagLoop(string $arg): string
	{
		$this->arrBlock[] = 'loop';
		if (!$arg) {
			throw new RuntimeException('Loop tag name is missing');
		}

		$depth = $this->depth + 1;
		if (str_contains($arg, ':')) {
			$data = $this->parseExpression(trim(explode(':', $arg, 2)[1]));
		} else {
			$data = $this->parseBlockArg($arg);
		}

		$code = sprintf('<?php if ($L%d = %s) { $i%d=-1; ', $depth, $data, $depth);
		$code .= sprintf('foreach ($L%d as $k%d=>$v%d) { $i%d++; ', $depth, $depth, $depth, $depth);
		$code .= '?>';

		$this->depth++;
		return $code;
	}

	protected function tagFor(string $arg): string
	{
		$this->arrBlock[] = 'for';
		$depth      = $this->depth + 1;

		$arg  = preg_replace('/(^\(|\)$)/', '', $arg);
		$code = sprintf('<?php $i%d=-1; for (%s) { $i%d++; ?>', $depth, $this->parseExpression($arg), $depth);
		$this->depth++;

		return $code;
	}

	protected function tagForeach(string $arg): string
	{
		$this->arrBlock[] = 'foreach';
		$depth        = $this->depth + 1;

		$arg = preg_replace('/(^\(|\)$)/', '', $arg);
		[$data, $keyValue] = array_map('trim', explode('as', $arg, 2));
		$format = '<?php $tmp=%s; if (!empty($tmp)) { $i%d]=-1; foreach ($tmp as %s) { $i%d++; ?>';
		$code   = sprintf($format, $this->parseExpression($data), $depth, $this->parseExpression($keyValue), $depth);

		$this->depth++;

		return $code;
	}

	protected function tagWhile(string $arg): string
	{
		$this->arrBlock[] = 'while';
		$depth      = $this->depth + 1;

		$arg  = preg_replace('/(^\(|\)$)/', '', $arg);
		$code = sprintf('<?php $i%d=-1; while (%s) { $i%d++; ?>', $depth, $this->parseExpression($arg), $depth);

		$this->depth++;

		return $code;
	}

	protected function tagIf(string $arg): string
	{
		$this->arrBlock[] = 'if';
		return sprintf('<?php if (%s) { ?>', $this->parseBlockArg($arg));
	}

	protected function tagElse(string $arg): string
	{
		$block = array_pop($this->arrBlock);
		if ($block === 'if' || $block === 'if-else') {
			$this->arrBlock[] = 'if-else';
			if ($arg) {
				$code = sprintf('<?php } elseif (%s) { ?>', $this->parseExpression($arg));
			} else {
				$code = '<?php } else { ?>';
			}
		} elseif ($block === 'loop') {
			$this->arrBlock[]  = 'else';
			$code = '<?php } } else { ?>';
			$this->depth--;
		} elseif ($block === 'each') {
			$this->arrBlock[]  = 'else';
			$code = '<?php } else { ?>';
			$this->depth--;
		} elseif ($block === 'foreach') {
			$this->arrBlock[]  = 'else';
			$code = '<?php } } else { ?>';
			$this->depth--;
		} elseif ($block === 'for' || $block === 'while') {
			$this->arrBlock[]  = 'else';
			$code = sprintf('<?php } if ($i%d==-1) { ?>', $this->depth);
			$this->depth--;
		} else {
			throw new RuntimeException('Else tag is invalid');
		}

		return $code;
	}

	protected function tagElvis(string $arg): string
	{
		$this->arrBlock[] = 'if';
		return sprintf('<?php if ($elvis=%s) { echo $elvis; } else { ?>', $this->parseExpression($arg));
	}

	protected function tagEnd(string $arg): string
	{
		$block = array_pop($this->arrBlock);
		if ($block === 'if' || $block === 'if-else' || $block === 'else') {
			$code = '<?php } ?>';
		} elseif ($block === 'loop') {
			$code = '<?php } } ?>';
			$this->depth--;
		} elseif ($block === 'each') {
			$code = '<?php } ?>';
			$this->depth--;
		} elseif ($block === 'foreach') {
			$code = '<?php } } ?>';
			$this->depth--;
		} elseif ($block === 'for' || $block === 'while') {
			$code = '<?php } ?>';
			$this->depth--;
		} else {
			throw new RuntimeException('End tag is invalid');
		}

		return $code;
	}

	protected function tagRefer(string $arg): string
	{
		$arg = preg_replace("/(^[\"']|[\"']$)/", '', $arg);
		if (str_contains($arg, ':')) {
			[$name, $file] = array_map('trim', explode(':', $arg, 2));
		} else {
			$name = $arg;
			$file = '';
		}
		if (str_starts_with($arg, '__')) {
			$name = sprintf("\$name.'_%s'", substr($arg, 2));
		} else {
			$name = sprintf("'%s'", $arg);
		}
		if ($file) {
			$code = sprintf('<?php $this->printBlock(%s, %s); ?>', $name, $this->parseExpression($file));
		} else {
			$code = sprintf('<?php $this->printBlock(%s); ?>', $name);
		}

		return $code;
	}

	protected function tagRaw(string $arg): string
	{
		return sprintf('<?=%s?>', $this->parseExpression(rtrim($arg, ';')));
	}

	protected function tagPhp(string $arg): string
	{
		return sprintf('<?php %s;?>', $this->parseExpression(rtrim($arg, ';')));
	}

	protected function tagInclude(string $arg): string
	{
		if ($arg[0] === '@') {
			$arg       = substr($arg, 1);
			$errorSafe = true;
		} else {
			$errorSafe = false;
		}

		$arg = preg_match('/["\']|^[^\/]+$/', $arg) ? $this->parseExpression($arg) : sprintf('"%s"', $arg);
		if ($errorSafe) {
			$code = sprintf('<?php ($tf = $this->getIncludeFile(%s, true, true)) && include $tf; ?>', $arg);
		} else {
			$code = sprintf('<?php include $this->getIncludeFile(%s, true, false); ?>', $arg);
		}

		return $code;
	}

	protected function tagExecute(string $arg): string
	{
		if ($arg[0] === '@') {
			$arg = substr($arg, 1);
			$cmd = '@include';
		} else {
			$cmd = 'include';
		}
		$arg  = preg_match('/["\']|^[^\/]+$/', $arg) ? $this->parseExpression($arg) : sprintf('"%s"', $arg);
		return sprintf('<?php %s %s; ?>', $cmd, $arg);
	}

	protected function tagDump(string $arg): string
	{
		if ($arg[0] === '@') {
			$arg       = substr($arg, 1);
			$cmd       = '@readfile';
			$errorSafe = 'true';
		} else {
			$cmd       = 'readfile';
			$errorSafe = 'false';
		}
		$arg           = preg_match('/["\']|^[^\/]+$/', $arg) ? $this->parseExpression($arg) : sprintf('"%s"', $arg);

		return sprintf('<?php %s($this->getIncludeFile(%s, false, %s)); ?>', $cmd, $arg, $errorSafe);
	}
	
	protected function parseVar(string $str): array|string|null
	{
		// @remark ignore ${...} style used in js template  
		return preg_replace_callback('/(?<!\$)\{' . self::PATTERN_VAR . '}/', [$this, 'parseVarCallback'], $str);
	}

	protected function parseVarCallback(array $match): string
	{
		return '<?=htmlspecialchars(' . $this->parseVarCommon($match) . ')?>';
	}

	protected function parseExpression(string $str): string
	{
		$php = '';
		$tmp = $this->tokenizeByQuote($str);
		for ($i = 0, $ci = count($tmp); $i < $ci; $i += 2) {
			if ($tmp[$i] !== '') {
				$php .= preg_replace_callback("/([^\w\h.]*\h*)(" . self::PATTERN_VAR . ")(\h*[^\w\h.]*)/", [__CLASS__, 'parseExpCallback'], $tmp[$i]);
			}
			$php .= $tmp[$i + 1] ?? '';
		}
		return $php;
	}

	protected function parseExpCallback(array $match)
	{
		$org  = array_shift($match);
		$prev = array_shift($match);
		$next = array_pop($match);

		// reserved
		if (preg_match('/^(if|else|elseif|do|while|for|foreach|as|switch|case|default|break|continue|echo|print|true|false|null|define|declare|include|include_once|require|require_once)$/', $match['var_name'])) {
			return $org;
		}

		// function
		if (preg_match("/^\h*\(/", (string)$next)) {
			if ($this->safeMode && $this->funcDeny && preg_match($this->funcDeny, $match[0])) {
				throw new RuntimeException('Not allowed expression : ' . $match[0] . '(...)');
			}
			if (!str_contains($match[0], '->')) {
				return $org;
			}
		}

		// normal var, object (include method)
		return $prev . $this->parseVarCommon($match) . $next;
	}

	protected function tokenizeByQuote($str): array
	{
		$tokens = [];
		$tmp = preg_split('/((?<!\\\)[\'"])/', $str, -1, PREG_SPLIT_DELIM_CAPTURE);

		$quotes = [];
		$index = 0;
		$tokens[$index] = $tmp[0];
		for ($i = 1, $ci = count($tmp); $i < $ci; $i+=2) {
			$token = $tmp[$i];
			$next  = $tmp[$i + 1] ?? '';
			if ($quotes) {
				$tokens[$index] .= $token;
				if ($token === end($quotes)) {
					array_pop($quotes);
					$index++;
					$tokens[$index] = $next;
				} else {
					$tokens[$index] .= $next;
				}
				continue;
			}
			$index++;
			$quotes[]       = $token;
			$tokens[$index] = $token . $next;
		}

		return $tokens;
	}

	protected function parseVarCommon(array $match): string
	{
		$code = match ($match['scope'][0] ?? '') {
			'_'     => $this->parseReservedVar($match),
			'.'     => $this->parseLoopVar($match),
			'c'     => $this->parseConstantVar($match),
			default => $this->parseNormalVar($match),
		};

		if (!empty($match['zerofill'])) {
			$code = $this->parseZerofill($code, $match['zerofill']);
		}

		if (!empty($match['func'])) {
			$code = $this->parseFunction($code, $match['func']);
		}

		return $code;
	}

	protected function parseReservedVar(array $match): string
	{
		$up = $match['var_up'] ? (int)substr($match['var_up'], 1) : 0;
		$depth = max($this->depth - $up, 0);

		$var_name = '_' . $match['var_name'];
		return match ($var_name) {
			'_index'  => sprintf('$i%d', $depth),
			'_number' => sprintf('$i%d+1', $depth),
			'_key'    => sprintf('$k%d', $depth),
			'_value'  => sprintf('$v%d', $depth) . $this->parseVarArray($match['var_array']) . $this->parseClassPart($match),
			default => $this->parseGlobalVar($match),
		};
	}

	protected function parseGlobalVar($match): string
	{
		if (in_array($match['var_name'], ['GET', 'POST', 'REQUEST', 'COOKIE', 'SESSION', 'SERVER', 'ENV', 'FILES'])) {
			if ($this->safeMode) {
				return sprintf("''/*%s*/", $match[0]);
			}
			return '$_' . $match['var_name'] . $this->parseVarArray($match['var_array']);
		}

		return sprintf('$v0[\'%s\']', $match['var_name']) . $this->parseVarArray($match['var_array']) . $this->parseClassPart($match);
	}

	protected function parseLoopVar($match): string
	{
		if ($match['var_up']) {
			$up = (int)substr($match['var_up'], 1);
		} else {
			$up = strlen($match['scope']) - 1;
		}
		$depth = max($this->depth - $up, 0);

		return sprintf('$v%d[\'%s\']', $depth, $match['var_name']) . $this->parseVarArray($match['var_array']) . $this->parseClassPart($match);
	}

	protected function parseConstantVar($match): string
	{
		$code = $match['ns'] . $match['var_name'];
		if ($match['var_array']) {
			return $code . $this->parseVarArray($match['var_array']) . $this->parseClassPart($match);
		}

		return $code . $match['class_type'] . $match['prop'] . $this->parseVarArray($match['prop_array']);
	}

	protected function parseNormalVar($match): string
	{
		if ($match['ns']) {
			return $match['ns'] . $match['var_name'] . $this->parseClassPart($match);
		}

		if ($match['var_name'] === 'GLOBALS') {
			return '$GLOBALS' . $this->parseVarArray($match['var_array']) . $this->parseClassPart($match);
		}

		if ($match['var_name'] === 'this' && $match['class_type'] === '->') {
			if ($this->safeMode) {
				return sprintf("''/*%s*/", $match[0]);
			}
			return '$this' . $this->parseClassPart($match);
		}

		if (preg_match('/^[i-n]$/', $match['var_name'])) {
			return '$' . $match['var_name'];
		}

		return sprintf('$v0[\'%s\']', $match['var_name']) . $this->parseVarArray($match['var_array']) . $this->parseClassPart($match);
	}

	protected function parseClassPart(array $match): string
	{
		if (!$match['class']) {
			return '';
		}

		$code = $match['class_type'];
		if ($code === '::') {
			$code .= '$' . $match['prop'];
		} else {
			$code .= $match['prop'];
		}

		return $code . $this->parseVarArray($match['prop_array']);
	}

	protected function parseVarArray(string $varArray): string
	{
		if ($varArray) {
			$var = substr($varArray, 1);
			$code = "['" . implode("']['", explode('.', $var)) . "']";

			// ['0'] --> [0], ['i'] --> [$i]
			return preg_replace(["/\['(\d+)']/", "/\['([i-n])']/"], ['[$1]', '[$$1]'], $code);
		}

		return '';
	}

	protected function parseZerofill(string $code, string $zerofill): string
	{
		$format = (int)substr($zerofill, 1);
		if ($format >= 2) {
			$code = sprintf('sprintf("%%0%dd", %s)', $format, $code);
		}

		return $code;
	}

	protected function parseFunction(string $code, string $str): string
	{
		$funcs = explode('|', substr($str, 1));
		foreach ($funcs as $v) {
			if (is_numeric($v)) {
				$v = 'length=' . $v;
			}
			if (str_contains($v, '=')) {
				[$func, $arg] = array_map('trim', explode('=', $v, 2));
			} else {
				$func = $v;
				$arg  = null;
			}
			$arg = match ($arg) {
				null, '' => $code,
				str_contains($arg, '##') => str_replace('##', $code, $this->correctEmptyArg($arg)),
				default => $code . ',' . $this->correctEmptyArg($arg),
			};

			if (method_exists($this->formatter, $func)) {
				$code = sprintf('_F::%s(%s)', $func, $arg);
			} else {
				$code = sprintf('%s(%s)', $func, $arg);
			}
		}

		return $code;
	}

	protected function parseBlockArg(string $arg): string
	{
		if (preg_match('/\W/', $arg)) {
			return $this->parseExpression($arg);
		}

		if (str_starts_with($arg, '__')) {
			$name = sprintf("\$name.'_%s'", substr($arg, 2));
		} else {
			$name = sprintf("'%s'", $arg);
		}
		if ($this->depth > 0) {
			return sprintf('$v%d[%s]??$v0[%s]??0', $this->depth, $name, $name);
		}
		return sprintf('$v0[%s]??0', $name);
	}

	protected function correctEmptyArg($arg): string
	{
		return implode(',', array_map(fn ($v) => trim($v) === '' ? "''" : $v, explode(',', $arg)));
	}

	protected function writeFile(string $file, string $str, int $chmod = 0606): int|false
	{
		$dir = dirname($file);
		if (!is_dir($dir)) {
			$this->makeDir($dir);
		}
		if ($r = @file_put_contents($file, $str)) {
			@chmod($file, $chmod);
		}
		return $r;
	}

	protected function makeDir(string $path, int $chmod = 0707): bool
	{
		if (!mkdir($path, $chmod, true) && !is_dir($path)) {
			throw new RuntimeException(sprintf('Directory "%s" was not created', $path));
		}
		@chmod($path, $chmod);
		return is_dir($path);
	}

	protected function debug(mixed $msg, string $label = '', ?array $extra = null): void
	{
		debug($msg, $label, $extra);
	}

	/**
	 * @throws \Nova\Exceptions\ErrorException
	 */
	protected function error(string $msg): bool|string
	{
		error($msg);
	}
}
