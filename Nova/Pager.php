<?php
namespace Nova;

use Nova\Support\Str;

class Pager
{
	public int $page;
	public int $totalNum;
	public int $listNum;
	public int $linkNum;
	public int $totalPage;

	public string $url;

	public string $theme    = 'default';
	public array  $themeSet = [
        'default' => [
			'method'         => 'default', // 페이징 방법
			'showSinglePage' => true, // 1 페이지만 있을 경우에도 표시
			'showFirstLast'  => false, // 앞쪽, 뒤쪽 링크 보임 (링크 불필요한 경우에도)
			'outerFirstLast' => true, // 처음,마지막 링크를 전후 링크 밖으로
			'thisPageHTML'   => '<strong>{page}</strong>', // 현재페이지 형식
			'otherPageHTML'  => '<a href="{url}">{page}</a>', // 기타페이지 형식
			'prevHTML'       => '<a class="prev" href="{url}">Prev</a>', // 이전페이지 텍스트
			'nextHTML'       => '<a class="next" href="{url}">Next</a>', // 다음페이지 텍스트
			'firstHTML'      => '<a class="first" href="{url}">First</a>', // 첫페이지 텍스트
			'lastHTML'       => '<a class="last" href="{url}">Last</a>', // 마지막페이지 텍스트
        ],
	];

	public function __construct(int $totalNum = 0, int $page = 1, int $listNum = 20, int $linkNum = 10)
	{
		$this->init($totalNum, $page, $listNum, $linkNum);
	}

	public function init(int $totalNum = 0, int $page = 1, int $listNum = 20, int $linkNum = 10): void
	{
		$this->page     = max($page, 1);
		$this->totalNum = max($totalNum, 0);
		$this->listNum  = max($listNum, 10);
		$this->linkNum  = max($linkNum, 1);

		$this->totalPage = (int)ceil($this->totalNum / $this->listNum);
		if ($this->page > $this->totalPage) {
			$this->page = $this->totalPage;
		}
	}

	public function setUrl(string $url): void
	{
		$url       = preg_replace('/=(%7Bpage%7D)(&|$)/', '={page}\2', $url);
		$this->url = $url;
	}

	public function display(string $theme = ''): string
	{
		if (!$this->totalNum) {
			return '';
		}

		$this->setTheme($theme);
		$themeSet = $this->getTheme();

		if ($this->totalPage === 1 && !$themeSet['showSinglePage']) {
			return '<!-- [1] -->';
		}

		$method = $themeSet['method'] ?: 'default';
		if ($method !== 'default') {
			$func = 'display' . Str::pascal($method);
			if (method_exists($this, $func)) {
				return $this->$func();
			}
		}

		return $this->displayDefault();
	}

	protected function setTheme(string $theme = '', array $themeSet = []): void
	{
		if (!$theme) {
			$theme = defined('__ADMIN__') ? 'admin' : 'default';
		}

		if ($themeSet) {
			$this->themeSet[$theme] = $themeSet;
		}
		$this->theme = isset($this->themeSet[$theme]) ? $theme : 'default';
	}

	protected function getTheme(string $theme = ''): array
	{
		$theme || $theme = $this->theme;
		$themeSet = &$this->themeSet[$theme];
		if (empty($themeSet['translate'])) {
			$themeSet = $this->procTranslate($themeSet);
		}

		return $themeSet;
	}

	protected function procTranslate(array $themeSet): array
	{
		foreach ($themeSet as $k => $v) {
			$themeSet[$k] = Lang::translate($v);
		}

		$themeSet['translate'] = true;
		return $themeSet;
	}

	public function displayDefault(): string
	{
		// calculate
		$startPage = max($this->page - ($this->page - 1) % $this->linkNum, 1);
		$endPage   = min($startPage + $this->linkNum - 1, $this->totalPage);
		$prevPage  = max($startPage - 1, 1);
		$nextPage  = min($endPage + 1, $this->totalPage);
		$showFirst = $this->page > $this->linkNum;
		$showLast  = $this->page <= $this->totalPage - ($this->totalPage % $this->linkNum);

		return $this->makeHtml($startPage, $endPage, $prevPage, $nextPage, $showFirst, $showLast);
	}

	public function makeHtml(int $startPage, int $endPage, int $prevPage, int $nextPage, bool $showFirst, bool $showLast): string
	{
		$themeSet = $this->themeSet[$this->theme];

		// links
		$result = [];

		// first, prev link
		if ($showFirst || $themeSet['showFirstLast']) {
			$urlFirst = str_replace('{page}', 1, $this->url);
			$urlPrev  = str_replace('{page}', $prevPage, $this->url);

			if ($themeSet['outerFirstLast']) {
				$result[] = str_replace(['{page}', '{url}'], [1, $urlFirst], $themeSet['firstHTML']);
				$result[] = str_replace(['{page}', '{url}'], [$prevPage, $urlPrev], $themeSet['prevHTML']);
			} else {
				$result[] = str_replace(['{page}', '{url}'], [$prevPage, $urlPrev], $themeSet['prevHTML']);
				$result[] = str_replace(['{page}', '{url}'], [1, $urlFirst], $themeSet['firstHTML']);
			}
		}

		// page link
		for ($i = $startPage; $i <= $endPage; $i++) {
			$url = str_replace('{page}', $i, $this->url);
			if ($i === $this->page) {
				$result[] = str_replace(['{page}', '{url}'], [$i, $url], $themeSet['thisPageHTML']);
			} else {
				$result[] = str_replace(['{page}', '{url}'], [$i, $url], $themeSet['otherPageHTML']);
			}
		}

		// last, next link
		if ($showLast || $themeSet['showFirstLast']) {
			$urlLast = str_replace('{page}', $this->totalPage, $this->url);
			$urlNext = str_replace('{page}', $nextPage, $this->url);

			if ($themeSet['outerFirstLast']) {
				$result[] = str_replace(['{page}', '{url}'], [$nextPage, $urlNext], $themeSet['nextHTML']);
				$result[] = str_replace(['{page}', '{url}'], [$this->totalPage, $urlLast], $themeSet['lastHTML']);
			} else {
				$result[] = str_replace(['{page}', '{url}'], [$this->totalPage, $urlLast], $themeSet['lastHTML']);
				$result[] = str_replace(['{page}', '{url}'], [$nextPage, $urlNext], $themeSet['nextHTML']);
			}
		}

		return implode('', $result);
	}
}
