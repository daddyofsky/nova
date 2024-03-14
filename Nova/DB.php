<?php
namespace Nova;

use ArrayObject;
use Closure;
use Nova\Support\CryptDB;
use Nova\Support\Str;
use Nova\Traits\ClassHelperTrait;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Class DB for MySQL
 */
class DB
{
	use ClassHelperTrait;

	public const INIT    = true;
	public const NO_INIT = false;

	public const MASTER = 1;
	public const SLAVE  = 0;

	public const AUTO_WHERE     = true;
	public const AUTO_HAVING    = true;
	public const NO_ORDER       = false;
	public const WHERE_TMP_ONLY = true;
	public const WHERE_NORMAL   = false;

	public const RAW_PREFIX = 'raw:';

	public const CREATED_AT = 'created_at';
	public const UPDATED_AT = 'updated_at';

	protected ?PDO $DB = null;
	protected ?PDO $slaveDB = null;

	public bool $useSlave = false;
	public int  $target   = self::MASTER;

	private static array $connections = [];

	private static array $instances = [];

	protected static string $cacheDir = '';

	protected string $dbname = '';
	protected int    $total  = -1;
	protected int    $offset = 0;

	protected array  $dsn          = [];
	protected string $charset      = 'utf8';
	protected mixed  $tablePrefix  = '';
	protected bool   $useFilter    = true;
	protected bool   $escape       = true;
	protected bool   $useLog       = false;
	protected bool   $useTimestamp = true;

	protected bool     $useCrypt     = false;
	protected ?CryptDB $cryptManager = null;
	
	protected string|array|Closure $callback = '';

	protected string $table     = '';
	protected string $PK        = '';
	protected string $alias     = '';
	protected array  $joins     = [];
	protected array  $columns   = [];
	protected array  $wheres    = [];
	protected array  $tmpWheres = [];
	protected array  $having    = [];
	protected array  $tmpHaving = [];
	protected string $order     = '';
	protected string $group     = '';
	protected int    $limit     = 0;
	protected int    $page      = 1;


	protected string $query       = '';
	protected string $queryBind   = '';
	protected int    $paramIndex  = 1;
	protected array  $params      = [];
	protected array  $tmpParams   = [];
	protected array  $tableSchema = [];
	protected array  $bulkData    = [];

	protected int   $subQueryDepth = 0;
	protected array $scopeBackup   = [];
	protected array $scopeVars     = [
		'callback' => '',
		'table'    => '',
		'PK'       => [],
		'alias'    => '',
		'joins'    => [],
		'columns'  => [],
		'wheres'   => [],
		'having'   => [],
		'order'    => '',
		'group'    => '',
		'limit'    => 0,
		'offset'   => 0,
		'page'     => 1,
	];

	public function __construct(string $dsn = '', string $charset = 'utf8')
	{
		$this->tablePrefix = $this->conf('db.table_prefix');

		$this->debug  = $this->conf('app.debug');
		$this->useLog = $this->conf('debug.db');

		if (!static::$cacheDir) {
			$this->setCacheDir(rtrim($this->conf('dir.tmp', sys_get_temp_dir()), '/') . '/_schema');
		}

		// db crypt conf
		if ($this->useCrypt) {
			$this->cryptManager = new CryptDB();
		}

		$this->connect($dsn ?: 'db.dsn', $charset);
	}

	/**
	 * @example
	 *     DB::factory();               // DB class
	 *     DB::factory('User');         // DB class
	 *     DB::factory('user.user_id'); // table_name.primary_key
	 */
	public static function factory(string $model = '', bool $init = self::INIT, string $dsn = ''): static|Model
	{
		[$model, $alias] = Str::split('/\h+/', trim($model) ?: '_', 2);
		$id = $model . ':' . $dsn;

		if (isset(self::$instances[$id])) {
			// singleton
			if ($init === self::INIT) {
				return self::$instances[$id]->init()->setAlias($alias);
			}
			return self::$instances[$id]->setAlias($alias);
		}

		// DB class
		if (preg_match('/^[A-Z]/', (string)$model)) {
			$className = str_contains($model, '\\') ? $model : '\\App\\Models\\' . $model;
			if (class_exists($className)) {
				self::$instances[$id] = new $className($dsn);
				return self::$instances[$id]->setAlias($alias);
			}
		}

		// TableName --> table_name
		$instance = self::$instances[$id] = new static($dsn);

		if ($model !== '_') {
			[$table, $pk] = Str::explode('.', (string)$model, 2);
			$instance->table(Str::snake($table), $pk);
		}

		return $instance->setAlias($alias);
	}

	public function master(): static
	{
		$this->target = self::MASTER;
		return $this;
	}

	public function slave(): static
	{
		if ($this->useSlave) {
			$this->target = self::SLAVE;
		}
		return $this;
	}

	public function connect(string $dsn, string $charset = 'utf8'): bool
	{
		// master db
		if (!$this->_connect($dsn, $charset, self::MASTER)) {
			return false;
		}

		// slave db
		$dsnSlave = $this->conf($dsn . '_slave');
		if ($dsnSlave) {
			if (!$this->_connect($dsnSlave, $charset, self::SLAVE)) {
				return false;
			}
			$this->useSlave = true;
			$this->target   = self::SLAVE;
		}

		return true;
	}

	public static function close(): void
	{
		foreach (self::$connections as $k => $v) {
			self::$connections[$k] = null;
		}
		foreach (self::$instances as $k => $v) {
			self::$instances[$k] = null;
		}
		self::$connections = [];
		self::$instances   = [];
	}

	public function isAlive(PDO $db = null): bool
	{
		$array = $db ? [$db] : array_filter([$this->DB, $this->slaveDB]);
		foreach ($array as $v) {
			$info = $v->getAttribute(PDO::ATTR_SERVER_INFO);
			if (!$info || preg_match('/gone away/i', (string)$info)) {
				return false;
			}
		}

		return true;
	}

	public function selectDB(string $dbname): bool|string
	{
		try {
			$this->DB->exec('USE ' . $dbname);
			$this->dbname = $dbname;

			// db crypt conf
			if ($this->useCrypt) {
				$this->cryptManager->selectDB($this->dbname);
			}
		} catch (PDOException) {
			return $this->error('DB Selection Error : ' . $dbname);
		}
		return true;
	}

	public function getDbName(): string
	{
		return $this->dbname;
	}

	public function beginTransaction(): bool
	{
		$this->debug('START TRANSACTION', sprintf('QUERY [%s]%s', 'M', '[T]'));

		try {
			if ($this->DB->inTransaction()) {
				return true;
			}
			return $this->DB->beginTransaction();
		} catch (PDOException) {
			// do nothing
		}
		return false;
	}

	public function commit(): bool
	{
		$this->debug('COMMIT', sprintf('QUERY [%s]%s', 'M', '[T]'));

		try {
			if (!$this->DB->inTransaction()) {
				return true;
			}
			if ($this->DB->commit()) {
				return true;
			}
		} catch (PDOException) {
			// do nothing
		}

		$this->rollback();
		return false;
	}

	public function rollback(): bool
	{
		$this->debug('ROLLBACK', sprintf('QUERY [%s]%s', 'M', '[T]'));

		try {
			if (!$this->DB->inTransaction()) {
				return true;
			}
			return $this->DB->rollBack();
		} catch (PDOException) {
			// do nothing
		}
		return false;
	}

	public function setTablePrefix(string $prefix): static
	{
		$this->tablePrefix = $prefix;
		return $this;
	}

	public function table(string|array $table, string $PK = ''): static
	{
		if (self::isRaw($table)) {
			$this->table = $table;
			$this->PK    = $PK;
			return $this;
		}

		$this->init();

		if (is_array($table)) {
			[$this->table, $this->PK] = $table;
		} else {
			// check table prefix and alias
			$this->table = $this->_getRealTable($table);
			$this->PK    = $PK;
		}

		if ($this->useCrypt) {
			$this->cryptManager->table($this->_removeAlias($table));
		}

		return $this;
	}

	public function getTable(string $table = ''): string
	{
		if ($table) {
			return $this->_getRealTable($table);
		}
		return $this->table;
	}

	public function alias(?string $alias): static
	{
		if ($alias) {
			$this->alias = $alias;
			$this->table = $this->_removeAlias($this->table) . ' ' . $alias;
		}
		return $this;
	}

	public function init(): static
	{
		$this->_initScope();

		$this->total       = -1;
		$this->query       = '';
		$this->queryBind   = '';
		$this->paramIndex  = 1;
		$this->params      = [];
		$this->tmpParams   = [];
		$this->bulkData    = [];
		$this->scopeBackup = [];

		return $this;
	}

	public function addJoin(string|array $table, string $joinType = '', string|array $on = '', string $column = ''): static
	{
		if (is_array($table)) {
			if (isset($table[0])) {
				if (is_array($table[0])) {
					foreach ($table as $args) {
						$this->addJoin(...(array)$args);
					}
				} else {
					$this->addJoin(...$table);
				}
			}
			return $this;
		}

		// check table prefix and alias
		$table = $this->_getRealTable($table);
		[$_table, $alias] = Str::explode(' ', $table, 2, $table);

		$joinOn = [];
		$this->_addWhere($joinOn, ...(array)$on);

		// check main table alias when add first join
		if (!$this->joins) {
			$query = '';
			foreach ($joinOn as $v) {
				$query .= ' ' . (is_array($v) ? $v[0] : (string)$v);
			}
			if (preg_match_all('/(\w+)\./', $query, $match, PREG_PATTERN_ORDER)) {
				$tmp = array_diff(array_unique($match[1]), (array)$alias);
				if (count($tmp) === 1) {
					// use array_pop() because index can be not 0
					$this->alias(array_pop($tmp));
				}
			}
		}

		$this->joins[$alias] = [$table, $joinType, $joinOn, $column];

		if ($this->useCrypt) {
			$this->cryptManager->addJoin($_table);
		}

		return $this;
	}

	public function callback(callable $callback, mixed ...$args): static
	{
		if ($callback && is_callable($callback)) {
			$this->callback = [$callback, ...$args];
		} else {
			$this->debug(__METHOD__ . '() : Invalid callback');
		}

		return $this;
	}

	public function iterate(array|ArrayObject $data, callable $callback, mixed ...$args): array|ArrayObject
	{
		if (array_is_list((array)$data)) {
			$ord = $this->total > 0 ? $this->total - $this->offset : count($data);
			foreach ($data as $i => &$v) {
				$v['__ord'] = $ord--;
				$callback($v, $i, ...$args);
			}
		} else {
			$callback($data, 0, ...$args);
		}

		return $data;
	}

	public function column(string|array $column): static
	{
		foreach ((array)$column as $v) {
			if ((string)$v !== '') {
				$v = self::unRaw((string)$v);
				if (!in_array($v, $this->columns, true)) {
					$this->columns[] = $v;
				}
			}
		}

		return $this;
	}

	/**
	 * add where condition
	 *
	 * @param mixed $type : =, !=, <, >, <=, >=, EQ, NE, LT, GT, LTE, GTE, BETWEEN, IN, LIKE, NOT IN, NOT LIKE
	 * @example
	 *
	 *    // raw where
	 *    addWhere('id = 1')
	 *    addWhere('id IN (1, 2)')
	 *
	 *     // normal (key, type, value)
	 *     addWhere('id', '=', 1)
	 *     addWhere('id', 1) --> addWhere('id', '=', 1) // omit type '='
	 *     addWhere('id', [1, 2, 3]) --> addWhere('id', 'IN', [1, 2, 3]) // omit type 'IN' (when value is array)
	 *     addWhere('id', 'in', 1) // type if case insensitive
	 *     addWhere('id', 'IN', [1, 2, 3])
	 *     addWhere('id', 'IN', 1, 2, 3)
	 *     addWhere('id', 'BETWEEN', [1, 4])
	 *     addWhere('id', 'BETWEEN', 1, 4)
	 *     addWhere('id', 'gte', 1, 'LT', 5)
	 *     addWhere('id', '>=', '', '<', 5) --> addWhere('id', '<', 5) // ignore empty value
	 *     addWhere('cate', 'LIKE', $keyword . '%')
	 *     addWhere('cate', 'LIKE', $keyword . '__')
	 *     addWhere('title', 'LIKE', $keyword) --> addWhere('id', 'LIKE', '%' . $keyword . '%')
	 *
	 *     // array (key => value)
	 *     addWhere(['id' => 1])
	 *     addWhere(['id' => [1, 2, 3]])
	 *     addWhere(['id' => ['IN', [1, 2, 3]]])
	 *     addWhere(['id' => ['IN', 1, 2, 3]])
	 *     addWhere(['id' => ['BETWEEN', [1, 4]])
	 *     addWhere(['id' => ['BETWEEN', 1, 4])
	 *     addWhere(['id' => ['gte', 1, 'AND', 'LT', 5])
	 *     addWhere(['id' => ['>=', '', '<', 5]) --> addWhere('id', '<', 5) // ignore empty value
	 *     addWhere(['cate' => ['LIKE', $keyword . '%'])
	 *     addWhere(['cate' => ['LIKE', $keyword . '__'])
	 *     addWhere(['title' => ['LIKE', $keyword])
	 *
	 *     // multiple (array)
	 *     addWhere([['id = 1'], ['passwd', $password]])
	 */
	public function where(string|array $key, mixed $type = '', mixed $value = ''): static
	{
		if ($key) {
			$this->_addWhere($this->wheres, ...func_get_args());
		}

		return $this;
	}

	public function orWhere(string|array ...$wheres): static
	{
		if ($wheres) {
			$this->_addOrWhere($this->wheres, $wheres);
		}

		return $this;
	}

	public function having(string|array $key, mixed $type = '', mixed $value = ''): static
	{
		if ($key) {
			$this->_addWhere($this->having, ...func_get_args());
		}

		return $this;
	}

	public function orHaving(string|array ...$having): static
	{
		if ($having) {
			$this->_addOrWhere($this->having, $having);
		}

		return $this;
	}

	public function orderBy(string|array $order): static
	{
		$this->order = implode(',', (array)$order);

		return $this;
	}

	public function groupBy(string|array $group): static
	{
		$this->group = implode(',', (array)$group);

		return $this;
	}

	public function limit(int|string $limit, int $offset = 0): static
	{
		if (str_contains((string)$limit, ',')) {
			$array = explode(',', (string)$limit);
			[$offset, $limit] = array_map('trim', $array);
		}

		$this->limit = max((int)$limit, 0);
		if ($offset) {
			$this->offset = (int)$offset;
			if ($this->limit > 0) {
				$this->page = (int)($this->offset / $this->limit) + 1;
			}
		} elseif ($this->page > 1) {
			$this->offset = ($this->page - 1) * $this->limit;
		}

		return $this;
	}

	public function page(int $page): static
	{
		$this->page = max($page, 1);
		if ($this->limit) {
			$this->offset = ($this->page - 1) * $this->limit;
		}

		return $this;
	}

	public function getPage(): int
	{
		return $this->page;
	}

	public function offset(int $offset): static
	{
		$this->offset = $offset;
		if ($this->limit) {
			$this->page = floor($this->offset / $this->limit) + 1;
		}

		return $this;
	}

	public function getOffset(): int
	{
		return $this->offset;
	}

	public function get(true|string|array $id = self::AUTO_WHERE, string $columns = ''): array|false
	{
		$where = $id === self::AUTO_WHERE ? $id : $this->_getPrimaryWhere($id);
		return $this->find($where, $columns, $this->order, $this->group);
	}

	public function findColumn(true|string|array $where = self::AUTO_WHERE, string $column = '', string|array $order = '', string|array $group = '', true|string|array $having = self::AUTO_HAVING): mixed
	{
		$sql = $this->_getSelectQuery($where, $column, 1, $order, $group, $having);
		if ($this->subQueryDepth) {
			return true;
		}
		return $this->fetchColumn($sql);
	}

	public function find(true|string|array $where = self::AUTO_WHERE, string $columns = '', string|array $order = '', string|array $group = '', true|string|array $having = self::AUTO_HAVING): mixed
	{
		$sql = $this->_getSelectQuery($where, $columns, 1, $order, $group, $having);
		if ($this->subQueryDepth) {
			return [];
		}

		return $this->fetch($sql);
	}

	public function findAll(true|string|array $where = self::AUTO_WHERE, string $columns = '', string|array $order = '', int|string $limit = '', string|array $group = '', true|string|array $having = self::AUTO_HAVING, int $page = 0): array
	{
		$sql = $this->_getSelectQuery($where, $columns, $limit, $order, $group, $having, $page);
		if ($this->subQueryDepth) {
			return [];
		}
		return $this->fetchAll($sql);
	}

	public function findCount(true|string|array $where = self::AUTO_WHERE, string $column = '*', string|array $group = '', true|string|array $having = self::AUTO_HAVING): int
	{
		$column || $column = '*';
		if (stripos($column, 'COUNT') === false) {
			$column = 'COUNT(' . $column . ')';
		}
		$sql = $this->_getSelectQuery($where, $column, 1, self::NO_ORDER, $group, $having, 1);
		if ($this->subQueryDepth) {
			return 1;
		}
		return $this->total = (int)$this->fetchColumn($sql);
	}

	public function updateCount(true|string|array $where, string|array $column, int $count = 1): bool
	{
		if (is_array($column)) {
			$data = $column;
		} else {
			$data = [$column => $count];
		}

		foreach ($data as $k => $v) {
			$v = (int)$v;
			if ($v < 0) {
				$data[$k] = self::raw($k . $v);
			} else {
				$data[$k] = self::raw($k . '+' . $v);
			}
		}
		return $this->update($data, $where, true);
	}

	public function setTotal(int $total): void
	{
		$this->total = $total;
	}

	public function total(): int
	{
		return $this->total;
	}

	public function insert(array|ArrayObject $data, array|bool $data_update = false): bool
	{
		if ($this->useTimestamp) {
			$data[self::CREATED_AT] = date('Y-m-d H:i:s');
		}
		$data = $this->_filter($this->table, $data);
		if ($this->callback) {
			$data = $this->iterate($data, ...$this->callback);
			$this->callback = '';
		}
		if (!$data) {
			$this->debug(__METHOD__ . '() : No insert data');
			return false;
		}
		if ($this->useCrypt) {
			$this->cryptManager->encryptData($data);
		}
		$sql = $this->_getInsertQuery($data, $data_update);
		return (bool)$this->_query($sql, $this->tmpParams);
	}

	public function insertAndGetId(array|ArrayObject $data, array|bool $data_update = false): int|string|bool
	{
		if ($this->insert($data, $data_update)) {
			return $this->getInsertId();
		}
		
		return false;
	}

	public function insertBulk(array $data = [], bool|int $count = 100): bool
	{
		if ($count === true) {
			// insert at once
			foreach ($data as &$v) {
				$v = $this->_filter($this->table, $v);
			}
			unset($v);
			if ($this->callback) {
				$data = $this->iterate($data, ...$this->callback);
			}
			if ($this->useCrypt) {
				$this->cryptManager->encryptData($data);
			}
			$sql = $this->_getInsertBulkQuery($data);
			$r   = (bool)$this->_query($sql);
			$this->init();
			return $r;
		}

		if ($data) {
			$data = $this->_filter($this->table, $data);
			if ($this->callback) {
				$data = (array)$this->iterate($data, ...$this->callback);
			}
			if ($this->useCrypt) {
				$this->cryptManager->encryptData($data);
			}
			$this->bulkData[] = $data;
			if (count($this->bulkData) < $count) {
				return true;
			}
		}

		if ($this->bulkData) {
			$sql = $this->_getInsertBulkQuery($this->bulkData);
			$r   = (bool)$this->_query($sql);
			$this->init();
			return $r;
		}
		return true;
	}

	public function findAndInsert(array|ArrayObject $data, true|string|array $where = self::AUTO_WHERE, string $to_table = ''): bool
	{
		$keys = $values = [];
		foreach ($data as $k => $v) {
			$keys[]   = is_int($k) ? $v : $k;
			$values[] = $v;
		}

		$select_sql = $this->_getSelectQuery($where, implode(',', $values));

		$table_org   = $this->table;
		$this->table = $this->_getRealTable($to_table ?: $this->table, false);
		$table       = $this->_removeAlias($this->table);
		/** @noinspection SqlNoDataSourceInspection */
		$sql = sprintf('INSERT INTO %s (%s) %s', $table, implode(',', $keys), $select_sql);

		$r           = (bool)$this->_query($sql);
		$this->table = $table_org;

		return $r;
	}

	public function getInsertId(bool $new = false): int|string
	{
		if ($new) {
			return $this->getNewInsertId();
		}
		return $this->DB->lastInsertId();
	}

	public function getNewInsertId(): int
	{
		$sql    = 'SHOW CREATE TABLE ' . $this->table;
		$schema = $this->DB->query($sql, PDO::FETCH_NUM)->fetchColumn(1);
		if (preg_match('/AUTO_INCREMENT=(\d]+)/i', (string)$schema, $match)) {
			return (int)$match[1];
		}

		return 1;
	}

	public function getTableSchema(string $table = '', bool $force = false): bool|array|string
	{
		$table || $table = $this->table;
		$cacheFile = static::$cacheDir . '/' . md5($this->dbname . '.' . $table);

		$flag = false;
		if (!$force && !$this->debug) {
			if (empty($this->tableSchema[$table])) {
				$this->tableSchema[$table] = json_decode(base64_decode(@file_get_contents($cacheFile)), true, 512, JSON_THROW_ON_ERROR);
			}
			$flag = !empty($this->tableSchema[$table]);
		}
		if (!$flag) {
			$schema = $this->_getTableSchema($table);
			if ($schema) {
				$this->tableSchema[$table] = $schema;
				file_put_contents($cacheFile, base64_encode(json_encode($schema, JSON_THROW_ON_ERROR)));
				@chmod($cacheFile, 0606);
			} else {
				return $this->error('Fail to get schema');
			}
		}
		return $this->tableSchema[$table];
	}

	public function update(array|ArrayObject $data, true|string|array $id = self::AUTO_WHERE, bool $isWhere = false, string|array $order = '', int $limit = 0): bool
	{
		// callback
		if ($this->callback) {
			$data = $this->iterate($data, ...$this->callback);
			$this->callback = '';
		}
		if ($this->useTimestamp) {
			$data[self::UPDATED_AT] = date('Y-m-d H:i:s');
		}
		$data = $this->_filter($this->table, $data);
		if (!$data) {
			$this->debug(__METHOD__ . '() : No update data');
			return false;
		}
		if ($this->useCrypt) {
			$this->cryptManager->encryptData($data);
		}
		$where = $id === self::AUTO_WHERE ? $id : $this->_getPrimaryWhere($id, $isWhere);
		$useTmpOnly = $where === self::AUTO_WHERE ? self::WHERE_NORMAL : self::WHERE_TMP_ONLY;
		if ($sql = $this->_getUpdateQuery($data, $where, $order, $limit, $useTmpOnly)) {
			$r = $this->_query($sql, $useTmpOnly ? $this->tmpParams : []);
			return (bool)$r;
		}
		return false;
	}

	public function delete(true|string|array $id = self::AUTO_WHERE, bool $isWhere = false, string|array $order = '', int $limit = 0): bool
	{
		$where = $id === self::AUTO_WHERE ? $id : $this->_getPrimaryWhere($id, $isWhere);
		$useTmpOnly = $where === self::AUTO_WHERE ? self::WHERE_NORMAL : self::WHERE_TMP_ONLY;
		if ($sql = $this->_getDeleteQuery($where, $order, $limit, $useTmpOnly)) {
			$r = $this->_query($sql, $useTmpOnly ? $this->tmpParams : []);
			return (bool)$r;
		}
		return false;
	}

	public function query(true|string|array $where = self::AUTO_WHERE, string $columns = '', string|array $order = '', int|string $limit = '', string|array $group = '', true|string|array $having = self::AUTO_HAVING, int $page = 0): bool|string|PDOStatement
	{
		$sql = $this->_getSelectQuery($where, $columns, $limit, $order, $group, $having, $page);
		return $this->_query($sql);
	}

	public function execute(string $sql, array $params = []): bool|string|PDOStatement
	{
		return $this->_query($sql, $params);
	}

	public function getQuery(): string
	{
		return $this->query ?: $this->queryBind;
	}

	public function subQuery(string $table, callable $callback, string $alias = ''): string
	{
		$this->_startSubQuery($table);
		$callback($this);
		return $this->_endSubQuery($alias);
	}

	public function explainQuery(string $sql): array
	{
		$data = [];
		if (preg_match('/^SELECT\s+/i', ltrim($sql))) {
			$stmt = $this->DB->query('EXPLAIN ' . $sql, PDO::FETCH_ASSOC);
			if ($stmt) {
				foreach ($stmt as $row) {
					$data[] = $row;
				}
			}
		}
		return $data;
	}

	public function fetchColumn(string $sql, array $params = []): mixed
	{
		if (!$stmt = $this->_query($sql, $params)) {
			return false;
		}

		$stmt->bindColumn(1, $result);
		$stmt->fetch(PDO::FETCH_BOUND);
		if ($this->debug) {
			$this->debug($result, sprintf('QUERY RESULT [%s]%s', ($this->target === self::MASTER ? 'M' : 'S'), ($this->DB->inTransaction() ? '[T]' : '')));
		}
		return $result;
	}

	public function fetch(string $sql, array $params = [], int $fetchStyle = PDO::FETCH_ASSOC): mixed
	{
		if (!$stmt = $this->_query($sql, $params)) {
			return [];
		}

		$data = $stmt->fetch($fetchStyle);
		if ($this->debug) {
			$this->debug($data, sprintf('QUERY RESULT [%s]%s', ($this->target === self::MASTER ? 'M' : 'S'), ($this->DB->inTransaction() ? '[T]' : '')));
		}
		if ($data) {
			if ($this->useCrypt) {
				$this->cryptManager->decryptData($data);
			}
			if ($this->callback) {
				$data = $this->iterate($data, ...$this->callback);
				$this->callback = '';
			}
		}
		return $data;
	}

	public function fetchAll(string $sql, array $params = [], int $fetchStyle = PDO::FETCH_ASSOC): array
	{
		if (!($stmt = $this->_query($sql, $params))) {
			return [];
		}

		$data = $stmt->fetchAll($fetchStyle);
		if ($this->debug) {
			$this->debug($data, sprintf('QUERY RESULT [%s]%s', ($this->target === self::MASTER ? 'M' : 'S'), ($this->DB->inTransaction() ? '[T]' : '')));
		}
		if ($data) {
			if ($this->useCrypt) {
				$this->cryptManager->decryptData($data);
			}
			if ($this->callback) {
				$data = $this->iterate($data, ...$this->callback);
				$this->callback = '';
			}
		}
		return $data;
	}

	public function setCacheDir(string $dir): void
	{
		static::$cacheDir = $dir;
		$this->debug(static::$cacheDir, 'DB : CACHE');
		if (!file_exists($dir) && !mkdir(static::$cacheDir, 0707, true) && !is_dir(static::$cacheDir)) {
			throw new RuntimeException(sprintf('Directory "%s" was not created', static::$cacheDir));
		}
	}

	public function getCacheDir(): string
	{
		return static::$cacheDir;
	}

	public function clearCache(string $dir = ''): void
	{
		if (!$dir) {
			$dir = static::$cacheDir;
		}

		if ($dir && ($d = dir($dir))) {
			while ($el = $d->read()) {
				if ($el[0] === '.') {
					continue;
				}
				$path_real = $dir . '/' . $el;
				if (is_dir($path_real)) {
					$this->clearCache($path_real);
					@rmdir($path_real);
				} else {
					@unlink($path_real);
				}
			}
		}
	}

	public function escape(mixed $data): array|string
	{
		if (is_array($data)) {
			foreach ($data as $key => $val) {
				$key        = $this->_escape($key);
				$val        = $this->_escape($val);
				$data[$key] = $val;
			}
			return $data;
		}
		return $this->_escape($data);
	}

	public static function isRaw(?string $str): bool
	{
		return $str && str_starts_with($str, self::RAW_PREFIX);
	}

	public static function raw(string $query): string
	{
		return self::RAW_PREFIX . $query;
	}

	public static function unRaw(string $str): string
	{
		if (self::isRaw($str)) {
			return substr($str, strlen(self::RAW_PREFIX));
		}

		return $str;
	}

	public function useLog(bool $flag = true): static
	{
		$this->useLog = $flag;
		return $this;
	}

	public function log(string $msg = ''): void
	{
		Log::save($msg ?: $this->query, Log::LOG_QUERY);
	}

	public function paginate($limit = 10, $columns = '', $pageName = 'page', int $page = 0, int $total = -1): array
	{
		$this->limit($limit);
		$this->page($page ?: request($pageName, 1));

		if ($columns) {
			$this->column($columns);
		}

		if ($total < 0) {
			$total = $this->findCount();
		}
		$this->total = $total;

		if ($total > 0) {
			return $this->findAll();
		}
		return [];
	}

	public function links(int $linkCount = 10, string $url = ''): string
	{
		$total = $this->total;
		if ($total > 0) {
			$url || $url = route('', request()->get()->merge(['page' => '{page}']));

			$page  = $this->page ?: (int)request('page', 1);
			$Pager = new Pager($total, $page, $this->limit, $linkCount);
			$Pager->setUrl($url);
			return $Pager->display();
		}
		return '';
	}

	////////////////////////////////////////////////////////////////////////////////////////////////
	/// crypt

	////////////////////////////////////////////////////////////////////////////////////////////////
	/// protected

	protected function _initScope(array $except = ['table', 'PK']): void
	{
		foreach ($this->scopeVars as $k => $v) {
			if (!in_array($k, $except, true)) {
				$this->{$k} = $v;
			}
		}
	}

	protected function _backupScope(): void
	{
		$backup = [];
		foreach ($this->scopeVars as $k => $v) {
			$backup[$k] = $this->{$k};
			$this->{$k} = $v;
		}
		$this->scopeBackup[] = $backup;
	}

	protected function _restoreScope(): void
	{
		$backup = array_pop($this->scopeBackup);
		foreach ($backup as $k => $v) {
			$this->{$k} = $v;
		}
	}

	protected function _connect(string $dsn, string $charset, int $target): bool|string
	{
		if (empty(self::$connections[$dsn]) /*|| !$this->isAlive(self::$connections[$id])*/) {
			// dsn info
			if (!$dbInfo = $this->_getDsn($dsn)) {
				return false;
			}
			if ($target === self::MASTER) {
				$this->dbname = $dbInfo['dbName'];
			}
			$charset = str_replace('-', '', $charset) ?: 'utf8';

			// singleton by dsn
			$pdoDsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s', $dbInfo['driver'], $dbInfo['dbHost'], $dbInfo['dbPort'] ?? 3306, $dbInfo['dbName'], $charset);
			$id = md5($pdoDsn);
			if (empty(self::$connections[$id])) {
				try {
					$options = [
						PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					];
					if (PHP_VERSION_ID < 50306) {
						$options[PDO::MYSQL_ATTR_INIT_COMMAND] = sprintf('SET NAMES %s', $charset);
					}

					self::$connections[$id] = null; // close connection if exists
					self::$connections[$id] = new PDO($pdoDsn, $dbInfo['dbUser'], $dbInfo['dbPass'], $options);
					$this->debug(sprintf('[%s] %s', $target === self::MASTER ? 'MASTER' : 'SLAVE', $dbInfo['dbName']), 'DB : CONNECT');

					if ($this->useCrypt) {
						if (CryptDB::isCbcCipher()) {
							$cipher = CryptDB::getCipher();
							self::$connections[$id]->prepare('SET block_encryption_mode = ?')->execute([$cipher]);
							$this->debug(sprintf("[%s] SET block_encryption_mode '%s'", $target === self::MASTER ? 'MASTER' : 'SLAVE', $cipher), 'DB : ENCRYPT');
						}
						$this->cryptManager->selectDB($this->dbname);
					}
				} catch (PDOException $e) {
					$msg = 'DB Connection Error';
					if ($this->debug) {
						$msg .= ' : ' . $e->getMessage();
					}
					return $this->error($msg);
				}
			}
			self::$connections[$dsn] = &self::$connections[$id];
		}

		if ($target === self::MASTER) {
			$this->DB || $this->DB = self::$connections[$dsn];
			return true;
		}

		$this->slaveDB || $this->slaveDB = self::$connections[$dsn];
		return true;
	}

	protected function _getDsn(string $dsn): bool|array|string
	{
		str_starts_with($dsn, 'db.dsn') && $dsn = $this->conf($dsn);
		if (!$dsn) {
			return $this->error('No DSN');
		}

		str_contains($dsn, '://') || $dsn = CryptDB::decrypt($dsn);
		$info = parse_url($dsn);
		if (!$info) {
			$info = $this->_parseDsn($dsn);
		}

		$dbInfo            = [];
		$dbInfo['driver']  = str_replace('mysqli', 'mysql', $info['scheme']);
		$dbInfo['dbHost']  = $info['host'];
		$dbInfo['dbPort']  = $info['port'] ?? 3306;
		$dbInfo['dbUser']  = $info['user'];
		$dbInfo['dbPass']  = $info['pass'];
		$dbInfo['dbName']  = substr($info['path'], 1);

		return $dbInfo;
	}

	protected function _parseDsn(string $dsn): array
	{
		[$scheme, $other] = explode('://', $dsn, 2);
		if ($pos = strrpos($other, '@')) {
			[$user, $pass] = Str::explode(':', substr($other, 0, $pos), 2);
			[$host, $path] = Str::explode('/', substr($other, $pos + 1), 2);
		} else {
			$user = $pass = '';
			[$host, $path] = Str::explode('/', $other, 2);
		}
		$path = '/' . $path;
		return compact('scheme', 'user', 'pass', 'host', 'path');
	}

	protected function _getRealTable(string $table, bool $useAlias = true): string
	{
		if (str_contains($table, '.') || self::isRaw($table)) {
			return $table;
		}

		[$table, $alias] = Str::split('/\s+(AS\s+)?/i', trim($table), 2);
		
		// table prefix
		if ($this->tablePrefix && !str_starts_with($table, $this->tablePrefix . '_')) {
			$table = $this->tablePrefix . '_' . $table;
		}

		// alias
		if ($alias && $useAlias) {
			return $table . ' ' . $alias;
		}

		return $table;
	}

	/**
	 * get table join query string - Using Mysql specific code
	 */
	protected function _getJoinedTable($where, $columns = ''): string
	{
		$table = self::unRaw($this->table);
		if (!$this->joins) {
			return $table;
		}

		$tmp = [];
		foreach ($this->joins as $alias => [$joinTable, $joinType, $on]) {
			$pattern = '/(?<=^|\W)' . $alias . '\./';
			if (preg_match($pattern, $where) || preg_match($pattern, $columns)) {
				// only if exists in where or columns
				[$queries, $params] = $this->_extractWhereParams($on);
				$tmp[] = sprintf('%s JOIN %s ON %s', $joinType, $joinTable, implode(' AND ', $queries));
				$this->params += $params;
			}
		}
		if ($tmp) {
			$table .= ' ' . implode(' ', $tmp);
		}
		return $table;
	}

	protected function _getJoinedColumn(string $columns): string
	{
		$columns = trim($columns);

		if (!$this->joins) {
			return $columns ?: '*';
		}
		if (str_starts_with($columns, 'COUNT(')) {
			// COUNT()
			return $columns;
		}

		$result = [];
		foreach ($this->joins as $join) {
			if (!empty($join[3])) {
				$result[] = $join[3];
			}
		}

		if ($result) {
			$columns = $columns ?: $this->alias . '.*';
			return $columns . ', ' . implode(', ', $result);
		}

		return $columns ?: '*';
	}

	protected function _query(string $sql, array $params = []): bool|string|PDOStatement
	{
		$sql = trim($sql);
		$params || $params = $this->params + $this->tmpParams;
		if ($params && !is_array($params)) {
			$params = [$params];
		}

		$inTransaction = $this->DB->inTransaction();

		try {

			// use master db if in transaction or sql is not select query
			$target = $this->target;
			if ($target === self::SLAVE) {
				$sqlType = strtoupper(substr($sql, 0, strpos($sql, ' ')));
				if (!$this->slaveDB || $inTransaction || !in_array($sqlType, ['SELECT', 'SET'])) {
					$target = self::MASTER;
				}
			}

			$start_time = microtime(true);

			if ($target !== self::MASTER) {
				$stmt = $this->slaveDB->prepare($sql);
			} else {
				$stmt = $this->DB->prepare($sql);
			}
			$stmt->setFetchMode(PDO::FETCH_ASSOC);

			if (isset($params[0]) && str_contains($sql, '?')) {
				// numbered query params
				foreach ($params as $i => $value) {
					$stmt->bindValue($i + 1, $value);
				}
			} else {
				// named query params
				foreach ($params as $key => $value) {
					if (is_array($value)) {
						$stmt->bindValue($key, $value[0], $value[1]);
					} else {
						$stmt->bindValue($key, $value);
					}
				}
			}
			$r = $stmt->execute();

			$end_time = microtime(true);

			$this->queryBind = $stmt->queryString;
			$this->query = $this->_makeRealQuery($this->queryBind, $params);
			if ($this->useLog && preg_match('/^(INSERT|UPDATE|DELETE)\h/i', $this->query)) {
				$this->log($this->query);
			}
			if ($this->debug) {
				$sql   = $this->query;
				$extra = [
					'time'       => $start_time,
					'term'       => $end_time - $start_time,
					'query_info' => $this->_queryInfo($stmt, $sql),
				];
				$label = sprintf('QUERY [%s]%s', ($target === self::MASTER ? 'M' : 'S'), ($inTransaction ? '[T]' : ''));
				$this->debug($sql, $label, $extra);
				if (!$r) {
					$error = $stmt->errorInfo();
					$this->debug(sprintf('[%d] %d : %s', $error[0], $error[1], $error[2]), 'QUERY ERROR');
				}
			}
			$this->tmpParams = [];
			return $r ? $stmt : false;
		} catch (PDOException $e) {
			// always log error
			$this->log('QUERY ERROR : ' . $e->getMessage());

			if ($inTransaction) {
				$this->debug($e->getMessage(), 'QUERY ERROR [M][T]');
				throw $e;
			}

			$msg = 'QUERY ERROR';
			if ($this->debug) {
				$msg .= ' : ' . $e->getMessage();
			}
			return $this->error($msg);
		}
	}

	protected function _addWhere(array &$target, string|array $key, mixed $type = '', mixed $value = ''): void
	{
		if (is_string($key)) {
			// single
			$target[] = $this->_parseWhere(...array_slice(func_get_args(), 1));
		} elseif (is_array($key[0] ?? null)) {
			// ex) [['key1', 'A'], ['key2', 'B']]
			foreach ($key as $arg) {
				if ($arg) {
					$this->_addWhere($target, ...(array)$arg);
				}
			}
		} elseif (isset($key[0])) {
			// single (array)
			$target[] = $this->_parseWhere(...$key);
		} else {
			foreach ($key as $k => $v) {
				$target[] = $this->_parseWhere($k, $v);
			}
		}
	}

	protected function _addOrWhere(array &$target, array $args): void
	{
		$parts = [];
		foreach ($args as $arg) {
			$part = [];
			$this->_addWhere($part, ...(array)$arg);

			if (count($part) === 1) {
				$parts[] = $part[0];
			} else {
				[$where, $param] = $this->_extractWhereParams($part);
				$parts[] = [
					'(' . implode(' AND ', $where) . ')',
					$param
				];
			}
		}

		[$wheres, $params] = $this->_extractWhereParams($parts);
		$target[] = [
			'(' . implode(' OR ', $wheres) . ')',
			$params
		];
	}

	protected function _parseWhere(string $key, mixed $type = '', mixed ...$value): array
	{
		if (!$key) {
			return [];
		}

		if ($this->useCrypt) {
			// use decrypt query for encrypted column
			$key = $this->cryptManager->applyDecryptColumnQuery($key);
		}

		// raw where
		if (func_num_args() === 1) {
			return [$key, []];
		}

		// raw where with ?
		if (str_contains($key, '?')) {
			return $this->_parseWherePlaceHolder($key, $type, ...$value);
		}

		// arrange type, value
		[$type, $value] = $this->_parseWhereArrangeArgs($type, $value);

		// type alias
		// ex) EQ --> =, LTE --> <=
		$type = $this->_parseWhereReplaceTypeAlias($type);

		// use type IN if value is array
		if (is_array($value)) {
			// ex) = --> IN, != --> NOT IN
			$type = $this->_parseWhereReplaceTypeArray($type);
		}

		if ($type === '=' || $type === '!=' || $type === '<>') {
			return $this->_parseWhereDefault($key, $type, $value);
		}

		if ($type === 'IN' || $type === 'NOT IN') {
			return $this->_parseWhereIn($key, $type, $value);
		}

		if ($type === 'LIKE' || $type === 'NOT LIKE') {
			return $this->_parseWhereLike($key, $type, $value);
		}

		if ($type === 'BETWEEN') {
			return $this->_parseWhereBetween($key, $type, $value);
		}

		if ($type === '<' || $type === '<=' || $type === '>' || $type === '>=') {
			return $this->_parseWhereRange($key, $type, $value);
		}

		// default
		return $this->_parseWhereDefault($key, $type, $value);
	}

	protected function _parseWherePlaceHolder(string $key, mixed ...$value): array
	{
		$params     = [];
		$index      = -1;
		$escapeChar = '!';

		$tmp = preg_split('/(\h+LIKE\h+)?(\\?)/i', $key, -1, PREG_SPLIT_DELIM_CAPTURE);
		for ($i = 2, $ci = count($tmp); $i < $ci; $i += 3) {
			$index++;

			if (!isset($value[$index])) {
				$tmp[$i] = "''";
				continue;
			}

			if ($tmp[$i - 1] !== '') {
				// escape like
				$org           = $value[$index];
				$value[$index] = $this->_escapeLike($value[$index], $escapeChar);
				if (strcmp($org, $value[$index])) {
					$tmp[$i + 1] = sprintf(" ESCAPE '%s' ", $escapeChar) . $tmp[$i + 1];
				}
			}

			[$tmp[$i], $param] = $this->_bindParamValue($value[$index]);
			$params += $param;
		}

		return [implode('', $tmp), $params];
	}

	protected function _parseWhereArrangeArgs(mixed $type, array $value): array
	{
		static $typePattern = '/^[=!<>]+|(EQ|NE|[LG]TE?|BETWEEN|(NOT )?IN|(NOT )?LIKE)$/i';

		if (!$value) {
			// argument count === 2
			if (is_array($type) && preg_match($typePattern, (string)$type[0])) {
				// ex) ['id', ['IN', ['A', 'B']]]
				return [$type, $value] = $type;
			}

			// ex) ['id', 'A'] or ['id', ['A', 'B']]
			$value = $type;
			$type  = is_array($value) ? 'IN' : '=';
			return [$type, $value];
		}

		if (!is_string($type) || !preg_match($typePattern, $type)) {
			// ex) ['id', 'A', 'B']
			$value = [$type, ...$value];
			return ['IN', $value];
		}

		if (count($value) === 1) {
			$value = $value[0];
		}
		return [$type, $value];
	}

	protected function _parseWhereReplaceTypeAlias(string $type): string
	{
		static $typeAlias = ['EQ' => '=', 'NE' => '!=', 'LT' => '<', 'LTE' => '<=', 'GT' => '>', 'GTE' => '>='];

		$type = strtoupper(trim($type));
		return $typeAlias[$type] ?? $type;
	}

	protected function _parseWhereReplaceTypeArray(string $type): string
	{
		static $typeArray = ['=' => 'IN', '!=' => 'NOT IN'];

		return $typeArray[$type] ?? $type;
	}

	protected function _parseWhereDefault(string $key, string $type, mixed $value): array
	{
		if ($value === null) {
			if ($type === '=') {
				return [sprintf('ISNULL(%s)', $key), []];
			}
			if ($type === '!=' || $type === '<>') {
				return [sprintf('!ISNULL(%s)', $key), []];
			}
		}

		// ex) ['key', '=', 3] --> key = 3
		[$paramKey, $param] = $this->_bindParamValue($value);
		return [sprintf('%s %s %s', $key, $type, $paramKey), $param];
	}

	protected function _parseWhereIn(string $key, string $type, mixed $value): array
	{
		if ($value) {
			// ex) ['key', 'in', 'A', 'B', ...] --> key IN ('A', 'B', ...)
			// ex) ['key', 'IN', ['A', 'B', ...]] --> key IN ('A', 'B', ...)
			[$paramKey, $param] = $this->_bindParamValue((array)$value);
			return [sprintf('%s %s (%s)', $key, $type, $paramKey), $param];
		}
		return [];
	}

	protected function _parseWhereLike(string $key, string $type, mixed $value): array
	{
		// ex) ['key', 'like', 'search'] --> key LIKE '%search%'
		// ex) ['key', 'LIKE', 'search%'] --> key LIKE 'search%'
		if (is_string($value)) {
			$escapeChar = '!';
			$org        = $value;
			$value      = $this->_escapeLike($value, $escapeChar);
			[$paramKey, $param] = $this->_bindParamValue($value);
			if (strcmp($org, $value)) {
				return [sprintf("%s %s %s ESCAPE '%s'", $key, $type, $paramKey, $escapeChar), $param];
			}
			return [sprintf('%s %s %s', $key, $type, $paramKey), $param];
		}
		return [];
	}

	protected function _parseWhereBetween(string $key, string $type, mixed $value): array
	{
		[$min, $max] = array_pad((array)$value, 2, null);
		if (($min === null || $min === '') && ($max === null || $max === '')) {
			return [];
		}
		if ($max === null || $max === '') {
			// only first value is valid
			[$paramKey, $param] = $this->_bindParamValue($min);
			return [sprintf('%s >= %s', $key, $paramKey), $param];
		}
		if ($min === null || $min === '') {
			// only second value is valid
			[$paramKey, $param] = $this->_bindParamValue($max);
			return [sprintf('%s <= %s', $key, $paramKey), $param];
		}
		// ex) ['key', 'between', 'A', 'B'] --> key BETWEEN 'A' AND 'B'
		// ex) ['key', 'BETWEEN', ['A', 'B']] --> key BETWEEN 'A' AND 'B'
		[$paramKey, $param] = $this->_bindParamValue($min);
		[$paramKey2, $param2] = $this->_bindParamValue($max);
		return [sprintf('%s %s %s AND %s', $key, $type, $paramKey, $paramKey2), $param + $param2];
	}

	protected function _parseWhereRange(string $key, string $type, mixed $value): array
	{
		$where = $params = [];
		
		[$a, $type2, $b] = array_pad((array)$value, 3, null);
		
		if ($a !== null && $a !== '') {
			[$paramKey, $param] = $this->_bindParamValue($a);
			$where[] = sprintf('%s %s %s', $key, $type, $paramKey);
			$params  += $param;
		}
		if ($type2 && $b !== null && $b !== '') {
			[$paramKey, $param] = $this->_bindParamValue($b);
			$where[] = sprintf('%s %s %s', $key, $type2, $paramKey);
			$params  += $param;
		}

		if (isset($where[1])) {
			// ex) ['key', 'lt', 5, 'gte', 10] --> (key < '5' OR key >= '10')
			$andOr = ($a < $b && str_contains($type, '<')) ? ' OR ' : ' AND ';
			return ['(' . implode($andOr, $where) . ')', $params];
		}
		if (isset($where[0])) {
			// ex) ['key', 'lte', 5] --> key <= '5'
			return [$where[0], $params];
		}
		return [];
	}

	protected function _getPrimaryWhere(mixed $id, bool $isWhere = false): array
	{
		if ($isWhere || is_array($id)) {
			return $id;
		}
		return [$this->PK, $id];
	}

	protected function _getSelectQuery(true|string|array $where = '', string|array $columns = '', int|string $limit = '', string|array|bool $order = '', string|array $group = '', true|string|array $having = '', int $page = 0): string
	{
		if ($this->subQueryDepth > 0) {
			if ($where && $where !== self::AUTO_WHERE) {
				$this->_addWhere($this->tmpWheres, ...(array)$where);
			}
			$columns && $this->column($columns);
			$limit && $this->limit($limit);
			$order && $this->orderBy($order);
			$group && $this->groupBy($group);
			if ($having && $having !== self::AUTO_HAVING) {
				$this->_addWhere($this->tmpHaving, ...(array)$having);
			}
			$page && $this->page($page);
			return '';
		}

		if (!$whereQuery = $this->_getWhereQuery($where)) {
			$whereQuery = 1;
		}

		$columns = $columns ? (array)$columns : $this->columns;
		$columns = $this->_getJoinedColumn(implode(', ', $columns));
		$group || $group = $this->group;
		if (!$order && $order !== self::NO_ORDER) {
			$order = $this->order;
		}
		$limit || $limit = $this->limit;
		$page || $page = $this->page;

		$table = $this->_getJoinedTable($whereQuery, $columns);
		$sql   = 'SELECT ' . $columns . ' FROM ' . $table . ' WHERE ' . $whereQuery;
		if ($group) {
			$sql .= ' GROUP BY ' . $group;
		}
		if ($havingQuery = $this->_getHavingQuery($having)) {
			$sql .= ' HAVING ' . $havingQuery;
		}
		if ($order) {
			$sql .= ' ORDER BY ' . $order;
		}
		if ($limit) {
			if (str_contains($limit, ',')) {
				$sql .= ' LIMIT ' . $limit;
			} elseif ($page > 1) {
				$sql .= sprintf(' LIMIT %d OFFSET %d', $limit, ($page - 1) * $limit);
			} else {
				$sql .= sprintf(' LIMIT %d', $limit);
			}
		}

		return $sql;
	}

	protected function _getWhereQuery(true|string|array $where, bool $useTmpOnly = self::WHERE_NORMAL): string
	{
		$whereQuery = '';

		if ($where && $where !== self::AUTO_WHERE) {
			// direct passed where condition to update(), delete(), findColumn(), find(), findAll(), findCount() methods
			$this->_addWhere($this->tmpWheres, $where);
		}

		if ($this->wheres || $this->tmpWheres) {
			[$tmpQueries, $tmpParams] = $this->_extractWhereParams($this->tmpWheres);
			$this->tmpParams += $tmpParams;
			if ($useTmpOnly === self::WHERE_TMP_ONLY) {
				$whereQuery   = implode(' AND ', $tmpQueries);
			} else {
				[$queries, $params] = $this->_extractWhereParams($this->wheres);
				$this->params += $params;
				$whereQuery   = implode(' AND ', array_merge($queries, $tmpQueries));
			}
			$whereQuery      = $this->_fixAndOr($whereQuery);
			$this->tmpWheres = [];
		}

		return $whereQuery;
	}

	protected function _getHavingQuery(true|string|array $having): string
	{
		$havingQuery = '';

		if ($having && $having !== self::AUTO_HAVING) {
			// direct passed having condition to findColumn(), find(), findAll(), findCount() methods
			$this->_addWhere($this->tmpHaving, $having);
		}

		if ($this->having || $this->tmpHaving) {
			[$tmpQueries, $tmpParams] = $this->_extractWhereParams($this->tmpHaving);
			[$queries, $params] = $this->_extractWhereParams($this->having);
			$havingQuery     = implode(' AND ', array_merge($queries, $tmpQueries));
			$havingQuery      = $this->_fixAndOr($havingQuery);
			$this->params    += $params;
			$this->tmpParams += $tmpParams;
			$this->tmpHaving = [];
		}

		return $havingQuery;
	}

	protected function _startSubQuery(string $table): void
	{
		$this->subQueryDepth++;

		// backup scope
		$this->_backupScope();

		$this->table = $this->_getRealTable($table);
	}

	protected function _endSubQuery(string $alias = ''): string
	{
		$this->subQueryDepth--;

		$sql = self::raw('(' . $this->_getSelectQuery() . ')');
		if ($alias) {
			$sql .= ' AS ' . $alias;
		}

		// restore scope
		$this->_restoreScope();

		return $sql;
	}

	protected function _getInsertQuery(array $data, bool|array $data_update = false): string
	{
		$table = $this->_removeAlias($this->table);
		foreach ($data as $key => $val) {
			if (self::isRaw($val)) {
				$data[$key] = substr($val, 4);
			} else {
				[$data[$key], $param] = $this->_bindParamValue($val, $key);
				$this->tmpParams += $param;
			}
		}
		$sql = sprintf(/** @lang text */ 'INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($data)), implode(', ', array_values($data)));

		if ($data_update) {
			$item = [];
			if (is_array($data_update)) {
				$data_update = $this->_filter($table, $data_update);
				foreach ($data_update as $key => $val) {
					if (is_int($key)) {
						$item[] = $data[$val];
					} elseif (self::isRaw($val)) {
						$item[] = sprintf('%s = %s', $key, substr($val, 4));
					} else {
						[$name, $param] = $this->_bindParamValue($val, $key);
						$item[]       = sprintf('%s = %s', $key, $name);
						$this->tmpParams += $param;
					}
				}
			} else {
				foreach ($data as $key => $val) {
					$item[] = sprintf('%s = %s', $key, $val); // already applied param value
				}
			}
			$sql .= sprintf(' ON DUPLICATE KEY UPDATE %s', implode(', ', $item));
		}
		return $sql;
	}

	protected function _getInsertBulkQuery(array $data): string
	{
		$table   = $this->_removeAlias($this->table);
		$columns = array_keys((array)$data[0]);
		$values  = [];
		foreach ($data as $val) {
			$row = [];
			foreach ($val as $v) {
				if (self::isRaw($v)) {
					$row[] = substr($v, 4);
				} else {
					[$row[], $param] = $this->_bindParamValue($v);
					$this->tmpParams += $param;
				}
			}
			$values[] = sprintf('(%s)', implode(',', $row));
		}
		return sprintf(/** @lang text */ 'INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', $values));
	}

	protected function _getUpdateQuery(array $data, true|string|array $where, string|array $order = '', int $limit = 0, bool $useTmpOnly = self::WHERE_NORMAL): string
	{
		if (!$whereQuery = $this->_getWhereQuery($where, $useTmpOnly)) {
			return '';
		}

		$item = [];
		foreach ($data as $key => $val) {
			if (self::isRaw($val)) {
				$item[] = sprintf('%s = %s', $key, substr($val, 4));
			} else {
				[$name, $param] = $this->_bindParamValue($val, $key);
				$item[]       = sprintf('%s = %s', $key, $name);
				$this->tmpParams += $param;
			}
		}
		$sql = sprintf(/** @lang text */ 'UPDATE %s SET %s WHERE %s', $this->_getJoinedTable($whereQuery), implode(', ', $item), $whereQuery);

		if ($order) {
			$sql .= ' ORDER BY ' . $order;
		}
		if ($limit) {
			$sql .= ' LIMIT ' . $limit;
		}

		return $sql;
	}

	protected function _getDeleteQuery(true|string|array $where, string|array $order = '', int $limit = 0, bool $useTmpOnly = self::WHERE_NORMAL): string
	{
		if (!$whereQuery = $this->_getWhereQuery($where, $useTmpOnly)) {
			return '';
		}

		$table = $this->_removeAlias($this->table);
		$sql = sprintf(/** @lang text */ 'DELETE FROM %s WHERE %s', $table, $whereQuery);

		if ($order) {
			$sql .= ' ORDER BY ' . $order;
		}
		if ($limit) {
			$sql .= ' LIMIT ' . $limit;
		}

		return $sql;
	}

	protected function _bindParamValue(mixed $value, string $key = ''): array
	{
		if (is_array($value)) {
			$names     = $params = [];
			$base_name = $key ?: $this->paramIndex++;
			foreach ($value as $k => $v) {
				[$names[], $param] = $this->_bindParamValue($v, $base_name . 'x' . $k);
				$params += $param;
			}
			return [implode(',', $names), $params];
		}

		if (self::isRaw($value)) {
			return [preg_replace('/\s{2,}/', ' ', substr($value, 4)), []];
		}

		$name = sprintf(':z%s', $key ?: $this->paramIndex++);
		if ($value === null) {
			$param = [$name => [$value, PDO::PARAM_NULL]];
		} elseif (is_bool($value)) {
			$param = [$name => [$value, PDO::PARAM_BOOL]];
		} elseif (is_int($value)) {
			$param = [$name => [$value, PDO::PARAM_INT]];
		} else {
			$param = [$name => [(string)$value, PDO::PARAM_STR]];
		}
		return [$name, $param];
	}

	protected function _extractWhereParams(array $wheres): array
	{
		$queries = $params = [];
		foreach ($wheres as $v) {
			if ($v) {
				if (is_array($v)) {
					[$query, $param] = array_pad($v, 2, null);
					if ($query) {
						$queries[] = $query;
						$params += $param ?? [];
					}
				} else {
					$queries[] = $v;
				}
			}
		}
		return [$queries, $params];
	}

	protected function _fixAndOr(string $query): array|string|null
	{
		// '/(?| AND( (?:OR|AND) )AND |(\() (?:OR|AND) | (?:OR|AND) (\)))/i'
		return preg_replace('/(?|\h+AND(\h+(?:OR|AND)\h+)AND\h+|(\()\h+(?:OR|AND)\h+|\h+(?:OR|AND)\h+(\)))/i', '$1', $query);
	}

	protected function _makeRealQuery(string $sql, array $params): string
	{
		if (!$params) {
			return $sql;
		}

		foreach ($params as &$value) {
			$type = PDO::PARAM_STR;
			if (is_array($value)) {
				$type  = $value[1] ?? PDO::PARAM_STR;
				$value = $value[0] ?? null;
			}
			if ($type === PDO::PARAM_NULL || $value === null) {
				$value = 'NULL';
			} elseif ($type === PDO::PARAM_BOOL || is_bool($value)) {
				$value = $value ? 'TRUE' : 'FALSE';
			} elseif ($type === PDO::PARAM_INT || is_int($value)) {
				$value = (string)$value;
			} else {
				$value = $this->DB->quote((string)$value);
			}
		}
		unset($value);

		// numbered query params
		if (isset($params[0]) && str_contains($sql, '?')) {
			$format = str_replace(['%', '?'], ['%%', '%s'], $sql);
			return vsprintf($format, $params);
		}

		// named query params
		krsort($params); // ex) prevent :aa replace :aaa too
		$from = array_keys($params);
		$to   = array_values($params);
		return str_replace($from, $to, $sql);
	}

	protected function _queryInfo(PDOStatement $stmt, string $sql): array|string
	{
		$sql  = trim($sql);
		$type = strtoupper(substr($sql, 0, strpos($sql, ' ')));

		if ($type === 'SELECT') {
			return $this->explainQuery($sql);
		}

		if ($type === 'INSERT') {
			return 'Insert ID : ' . $this->DB->lastInsertId();
		}

		if (in_array($type, ['UPDATE', 'DELETE', 'REPLACE'])) {
			return 'Affected Rows : ' . $stmt->rowCount();
		}
		return '';
	}

	protected function _getTableSchema(string $table): array
	{
		$schema = [];
		$table  = $this->_getRealTable($table, false);
		$sql    = sprintf('SHOW COLUMNS FROM %s', $table);
		$tmp    = $this->DB->query($sql, PDO::FETCH_ASSOC);
		foreach ($tmp as $row) {
			$type = preg_split('/\(([\d,]+)\)/', strtolower($row['Type']), 2, PREG_SPLIT_DELIM_CAPTURE);
			$schema[$row['Field']] = [
				'column'  => $row['Field'],
				'type'    => $type[0],
				'length'  => $type[1] ?? 0,
				'null'    => ($row['Null'] === 'YES') ? 1 : 0,
				'default' => $row['Default'],
				'pk'      => ($row['Key'] === 'PRI') ? 1 : 0,
			];
		}
		return $schema;
	}

	protected function _filter(string $table, array|ArrayObject $data): array
	{
		if ($this->useFilter) {
			$schema = $this->getTableSchema($table);
			if ($schema) {
				$data = array_intersect_key((array)$data, $schema);
			}
		}
		return (array)$data;
	}

	protected function _escape(mixed $value): string
	{
		if (self::isRaw($value)) {
			return $value;
		}
		return substr($this->DB->quote($value), 1, -1);
	}

	protected function _escapeLike(string $str, string $char = '!'): string
	{
		$pattern = '/(?=[' . preg_quote($char, '/') . '_%])/';
		if (!preg_match('/^[_%]|[_%]$/', $str)) {
			return '%' . preg_replace($pattern, $char, $str) . '%';
		}

		$tmp = preg_split('/(^[%_]+|[%_]+$)/', $str, 3, PREG_SPLIT_DELIM_CAPTURE);
		$tmp[0] && $tmp[0] = preg_replace($pattern, $char, $tmp[0]);
		$tmp[2] && $tmp[2] = preg_replace($pattern, $char, $tmp[2]);
		return implode('', $tmp);
	}

	protected function _removeAlias(string $table): string
	{
		return preg_replace('/(\s+AS)?\s+\w+$/i', '', trim($table));
	}

	public function __debugInfo(): array
	{
		return ['@see' => __METHOD__ . '()'] + array_filter(get_object_vars($this), function($key) {
			return in_array($key, [
				'DB',
				'slaveDB',
				'useSlave',
				'dbname',
				'table',
				'tablePrefix',
				'PK',
				'error',
				'query',
				'queryBind',
				'total',
				'data',
			]);
		}, ARRAY_FILTER_USE_KEY);
	}

	////////////////////////////////////////////////////////////////////////////////////////////////
	// backward compatability

	/** @noinspection PhpUnused */
	public function setTable(...$args): static {return $this->table(...$args);}
	public function setAlias(...$args): static {return $this->alias(...$args);}
	/** @noinspection PhpUnused */
	public function addColumn(...$args): static {return $this->column(...$args);}
	public function addWhere(...$args): static {return $this->where(...$args);}
	/** @noinspection PhpUnused */
	public function addOrWhere(...$args): static {return $this->orWhere(...$args);}
	/** @noinspection PhpUnused */
	public function addHaving(...$args): static {return $this->having(...$args);}
	/** @noinspection PhpUnused */
	public function addOrHaving(...$args): static {return $this->orHaving(...$args);}
	/** @noinspection PhpUnused */
	public function setOrderBy(...$args): static {return $this->orderBy(...$args);}
	public function setGroupBy(...$args): static {return $this->groupBy(...$args);}
	public function setLimit(...$args): static {return $this->limit(...$args);}
	public function setPage(...$args): static {return $this->page(...$args);}
	/** @noinspection PhpUnused */
	public function getTotal(): int {return $this->total();}
	public function getPaging(int $total = 0, int $linkNum = 10, string $url = '', int $page = 0, int $limit = 0): string
	{
		$total = $total ?: $this->total;
		if ($total > 0) {
			$url || $url = route('', ['page' => '{page}']);

			$page  = $page ?: $this->page ?: (int)request('page', 1);
			$Pager = new Pager($total, $page, $limit ?: $this->limit, $linkNum ?: $this->linkNum);
			$Pager->setUrl($url);
			return $Pager->display();
		}
		return '';
	}}
