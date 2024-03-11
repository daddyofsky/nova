<?php
namespace Nova;

use Nova\Support\Arr;
use Nova\Support\ArrayData;
use Nova\Support\Str;
use Nova\Traits\ClassHelperTrait;
use Nova\Traits\SingletonTrait;

/**
 * @method static static|DB factory(string $model = '', bool $init = DB::INIT, string $dsn = '')
 * @method static static|DB master()
 * @method static static|DB slave()
 * @method static static|DB connect(string $dsn, string $charset = 'utf8')
 * @method static static|DB close()
 * @method static static|DB isAlive(\PDO $db = null)
 * @method static static|DB|string selectDB(string $dbname)
 * @method static string getDbName()
 * @method static static|DB beginTransaction()
 * @method static static|DB commit()
 * @method static static|DB rollback()
 * @method static static|DB setTablePrefix(string $prefix)
 * @method static static|DB table(string|array $table, string $PK = '')
 * @method static string getTable(string $table = '')
 * @method static static|DB alias(?string $alias)
 * @method static static|DB init()
 * @method static static|DB addJoin(string|array $table, string $joinType = '', string|array $on = '', string $column = '')
 * @method static static|DB callback(callable $callback, mixed ...$args)
 * @method static static|DB column(string|array $column)
 * @method static static|DB where(string|array $key, mixed $type = '', mixed $value = '')
 * @method static static|DB orWhere(string|array ...$wheres)
 * @method static static|DB having(string|array $key, mixed $type = '', mixed $value = '')
 * @method static static|DB orHaving(string|array ...$having)
 * @method static static|DB orderBy(string|array $order)
 * @method static static|DB groupBy(string|array $group)
 * @method static static|DB limit(int|string $limit, int $offset = 0)
 * @method static static|DB page(int $page)
 * @method static int getPage()
 * @method static static|DB offset(int $offset)
 * @method static int getOffset()
 * @method static static|DB get(true|string|array $id = DB::AUTO_WHERE, string $columns = '')
 * @method static mixed findColumn(true|string|array $where = DB::AUTO_WHERE, string $column = '', string|array $order = '', string|array $group = '', true|string|array $having = DB::AUTO_HAVING)
 * @method static static|DB find(true|string|array $where = DB::AUTO_WHERE, string $columns = '', string|array $order = '', string|array $group = '', true|string|array $having = DB::AUTO_HAVING)
 * @method static static|DB findAll(true|string|array $where = DB::AUTO_WHERE, string $columns = '', string|array $order = '', int|string $limit = '', string|array $group = '', true|string|array $having = DB::AUTO_HAVING, int $page = 0)
 * @method static int findCount(true|string|array $where = DB::AUTO_WHERE, string $column = '*', string|array $group = '', true|string|array $having = DB::AUTO_HAVING)
 * @method static static|DB updateCount(true|string|array $where, string|array $column, int $count = 1)
 * @method static static|DB setTotal(int $total)
 * @method static int|DB total()
 * @method static bool insert(array $data, array|bool $data_update = false)
 * @method static bool|int|string insertAndGetId(array $data, array|bool $data_update = false)
 * @method static static|DB insertBulk(array $data = [], bool|int $count = 100)
 * @method static static|DB findAndInsert(array $data, true|string|array $where = DB::AUTO_WHERE, string $to_table = '')
 * @method static int|string getInsertId(bool $new = false)
 * @method static int getNewInsertId()
 * @method static static|DB|string getTableSchema(string $table = '', bool $force = false)
 * @method static static|DB update(array $data, true|string|array $id = DB::AUTO_WHERE, bool $isWhere = false, string|array $order = '', int $limit = 0)
 * @method static static|DB delete(true|string|array $id = DB::AUTO_WHERE, bool $isWhere = false, string|array $order = '', int $limit = 0)
 * @method static static|DB|string|\PDOStatement query(true|string|array $where = DB::AUTO_WHERE, string $columns = '', string|array $order = '', int|string $limit = '', string|array $group = '', true|string|array $having = DB::AUTO_HAVING, int $page = 0)
 * @method static static|DB|string|\PDOStatement execute(string $sql, array $params = [])
 * @method static string getQuery()
 * @method static string subQuery(string $table, callable $callback, string $alias = '')
 * @method static array explainQuery(string $sql)
 * @method static mixed fetchColumn(string $sql, array $params = [])
 * @method static mixed fetch(string $sql, array $params = [], int $fetchStyle = \PDO::FETCH_ASSOC)
 * @method static mixed fetchAll(string $sql, array $params = [], int $fetchStyle = \PDO::FETCH_ASSOC)
 * @method static static|DB setCacheDir(string $dir)
 * @method static string getCacheDir()
 * @method static static|DB clearCache(string $dir = '')
 * @method static array|string escape(mixed $data)
 * @method static string isRaw(?string $str)
 * @method static string raw(string $query)
 * @method static static|DB unRaw(string $str)
 * @method static static|DB useLog(bool $flag = true)
 * @method static static|DB log(string $msg = '')
 * @method static static|DB paginate($limit = 10, $columns = '', $pageName = 'page', int $page = 0, int $total = -1)
 * @method static string links(int $linkCount = 10, string $url = '')
 * @method static static|DB setCrypt(string|array $columns)
 * @method static static|DB setCryptCallback(callable $encryptCallback, callable $decryptCallback)
 * @method static static|DB setCryptCipher(string $cipher)
 * @method static static|DB setCryptKey(string $salt)
 * @method static string encrypt(string $text, string $salt = '', string $cipher = '')
 * @method static string decrypt(string $hash, string $salt = '', string $cipher = '')
 * @method static string replaceColumnDecryptQuery(string $key)
 * @method static static|DB getColumnDecryptQuery(string $column, string $salt = '', string $cipher = '')
 */
class Model extends ArrayData
{
	use SingletonTrait, ClassHelperTrait;

	protected string $dsn     = 'db.dsn';
	protected string $charset = 'utf8';

	protected string $table      = '';
	protected string $primaryKey = 'id';
	protected string $foreignKey = '';

	protected ?DB $dbo = null;

	public function __construct($id = '')
	{
		$this->dbo = new DB($this->dsn, $this->charset);
		$this->dbo->table($this->table, $this->primaryKey);

		// @remark uninitialized property can cause conflict!!
		parent::__construct([]);

		if ($id) {
			$this->get($id);
		}
	}

	public function __call($method, $args)
	{
		// dbo method
		if (method_exists($this->dbo, $method)) {
			$result = $this->dbo->$method(...$args);
			if ($result instanceof $this->dbo) {
				return $this;
			}
			if (in_array($method, ['get', 'find', 'findAll', 'paginate'], true)) {
				if ($result && (array)$this) {
					// return new instance
					$instance = clone $this;
					$instance->exchangeArray($result);
					return $instance;
				}
				$this->exchangeArray($result ?: []);
				return $this;
			}
			return $result;
		}

		// scope method
		$scopeMethod = 'scope' . Str::camel($method);
		if (method_exists($this, $scopeMethod)) {
			return $this->$scopeMethod(...$args);
		}

		if (method_exists($this, $method)) {
			return $this->$method(...$args);
		}
		
		return null;
	}

	public static function make(...$args): static|DB
	{
		// ex) SomeExistsModel::make()
		$model = static::class;
		if ($model !== __CLASS__ || !$args) {
			return App::make($model, ...$args);
		}

		$model = $args[0];
		if (is_object($model)) {
			return $model;
		}

		$args = array_slice($args, 1);

		// ex) Model::make('table.pk alias')
		// ex) Model::make('ModelName.pk alias')
		// ex) Model::make('NotExistsModel alias')

		[$model, $alias] = Str::split('/\h+/', trim($model), 2);
		[$model, $pk] = Str::explode('.', $model, 2);

		if (str_contains($model, '\\')) {
			$class = $model;
			$model = substr($class, strrpos($class, '\\') + 1);
		} else {
			$class = '\\App\\Models\\' . Str::pascal($model);
		}
		if (class_exists($class)) {
			return App::make($class, ...$args);
		}

		$instance = new Model(...$args);
		$instance::table(table: Str::snake($model), PK: $pk ?? '')->alias($alias);

		return App::alias($instance, $model);
	}

	public function dbo(): DB
	{
		return $this->dbo;
	}

	public function getPrimaryKey(): string
	{
		return $this->primaryKey;
	}

	public function getForeignKey(): string
	{
		if ($this->foreignKey) {
			return $this->foreignKey;
		}

		if ($this->primaryKey === 'id') {
			return $this->getName() . '_id';
		}

		return $this->foreignKey = $this->primaryKey;
	}

	public function getName(): string
	{
		return Str::snake(Str::basename(static::class));
	}

	public function value($key): mixed
	{
		return $this[$key] ?? $this[0][$key] ?? null;
	}

	public function values($key): array
	{
		if ($this->isList()) {
			return array_unique(array_column((array)$this, $key));
		}
		return isset($this[$key]) ? [$this[$key]] : [];
	}

	public function with(...$methods): static
	{
		foreach ($methods as $method) {
			[$method, $columns] = Str::explode(':', $method, 2);
			$method = 'with' . Str::pascal($method);
			$this->$method($columns ?? '');
		}

		return $this;
	}

	public function join(...$methods): static
	{
		foreach ($methods as $method) {
			[$method, $columns] = explode(':', $method, 2);
			$method = 'join' . Str::pascal($method);
			$this->$method($columns ?? '');
		}

		return $this;
	}

	public function arrange(string|callable $callback = 'format', ...$args): static
	{
		$callback || $callback = 'format';
		if (is_string($callback)) {
			if (method_exists($this, $callback)) {
				$callback = [$this, $callback];
			} else {
				$callback = [$this, 'format' . Str::pascal($callback)];
			}
		}

		if (is_callable($callback)) {
			$this->dbo->iterate($this, $callback, ...$args);
		}

		return $this;
	}

	public function isList(): bool
	{
		return array_is_list((array)$this);
	}

	public function isAssoc(): bool
	{
		return !array_is_list((array)$this);
	}

	public function withSingle($to, $table, $by, $columns = '', $where = []): static
	{
		$data = $this->getWithData($table, $by, $columns ?: '*', $where);
		if ($this->isList()) {
			Arr::attach($this, $data, $by, $to);
		} else {
			$this[$to] = $data[0];
		}

		return $this;
	}

	public function withMany($to, $table, $by, $columns = '', $where = []): static
	{
		$data = $this->getWithData($table, $by, $columns ?: '*', $where);
		if ($this->isList()) {
			Arr::attachMany($this, $data, $by, $to);
		} else {
			$this[$to] = $data;
		}

		return $this;
	}

	protected function getWithData($table, $by, $columns, $where = []): array
	{
		[$this_key, $that_key] = Str::explode(':', $by, 2, $by);

		$values  = $this->values($this_key);
		if ($values) {
			if ($where && isset($where[0]) && !is_array($where[0])) {
				$where = [$where];
			}
			$where[] = [$that_key, $values];

			/** @noinspection SelfClassReferencingInspection */
			return (array)Model::make($table)->findAll($where, $columns)->arrange();
		}

		return [];
	}
}
