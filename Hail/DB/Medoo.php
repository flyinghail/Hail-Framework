<?php
/*!
 * Medoo database framework
 * http://medoo.in
 * Version 1.1.3
 *
 * Copyright 2016, Angel Lai
 * Released under the MIT license
 */

namespace Hail\DB;

use PDO;
use Hail\Utils\Json;


/**
 * 不包含 data map
 *
 * @package Hail\DB
 * @author  Hao Feng <flyinghail@msn.com>
 */
class Medoo
{
	// General
	protected $type;
	protected $charset;
	protected $database;

	// For MySQL, MariaDB, MSSQL, Sybase, PostgreSQL, Oracle
	protected $server;
	protected $username;
	protected $password;

	// For SQLite
	protected $file;

	// For MySQL or MariaDB with unix_socket
	protected $socket;

	// Optional
	protected $port;
	protected $prefix;
	protected $option = [];

	// use php-cp extension
	protected $extConnectPool = false;

	// Variable
	protected $lastId = [];

	/** @var Event */
	protected $event;

	/**
	 * @var PDO $pdo
	 */
	protected $pdo;

	public function __construct(array $options)
	{
		foreach ($options as $option => $value) {
			$this->$option = $value;
		}

		if ($this->extConnectPool) {
			$this->extConnectPool = class_exists('\pdoProxy');
		}

		if (
			isset($this->port) &&
			is_int($this->port * 1)
		) {
			$port = $this->port;
		}

		$this->type = $type = strtolower($this->type);

		$dsn = '';
		$commands = [];
		switch ($type) {
			case 'mariadb':
			case 'mysql':
				if ($this->socket) {
					$dsn = 'mysql:unix_socket=' . $this->socket . ';dbname=' . $this->database;
				} else {
					$dsn = 'mysql:host=' . $this->server . ';port=' . ($port ?? '3306') . ';dbname=' . $this->database;
				}

				// Make MySQL using standard quoted identifier
				$commands[] = 'SET SQL_MODE=ANSI_QUOTES';
				break;

			case 'pgsql':
				$dsn = 'pgsql:host=' . $this->server . ';port=' . ($port ?? '5432') . ';dbname=' . $this->database;
				break;

			case 'sybase':
				$dsn = 'dblib:host=' . $this->server . ':' . ($port ?? '5000') . ';dbname=' . $this->database;
				break;

			case 'oracle':
				$dbname = $this->server ?
					'//' . $this->server . ':' . ($port ?? '1521') . '/' . $this->database :
					$this->database;

				$dsn = 'oci:dbname=' . $dbname . ($this->charset ? ';charset=' . $this->charset : '');
				break;

			case 'mssql':
				$dsn = strstr(PHP_OS, 'WIN') ?
					'sqlsrv:server=' . $this->server . ',' . ($port ?? '1433') . ';database=' . $this->database :
					'dblib:host=' . $this->server . ':' . ($port ?? '1433') . ';dbname=' . $this->database;

				// Keep MSSQL QUOTED_IDENTIFIER is ON for standard quoting
				$commands[] = 'SET QUOTED_IDENTIFIER ON';
				break;

			case 'sqlite':
				$dsn = 'sqlite:' . $this->file;
				$this->username = null;
				$this->password = null;
				break;
		}

		if (
			$this->charset &&
			in_array($type, ['mariadb', 'mysql', 'pgsql', 'sybase', 'mssql'], true)

		) {
			$commands[] = "SET NAMES '" . $this->charset . "'";
		}

		$this->event('start', Event::CONNECT);

		$class = $this->extConnectPool ? '\pdoProxy' : 'PDO';
		$this->pdo = new $class(
			$dsn,
			$this->username,
			$this->password,
			$this->option
		);
		$this->event('done');

		foreach ($commands as $value) {
			$this->release(
				$this->exec($value)
			);
		}
	}

	/**
	 * @param $query
	 *
	 * @return bool|\PDOStatement
	 */
	public function query($query)
	{
		if (PRODUCTION_MODE || strpos($query, 'EXPLAIN') === 0) {
			return $this->pdo->query($query);
		}

		$this->event('sql', $query);
		$query = $this->pdo->query($query);
		$this->event('query');

		return $query;
	}

	/**
	 * @param $query
	 *
	 * @return bool|int
	 */
	public function exec($query)
	{
		if (PRODUCTION_MODE) {
			return $this->pdo->exec($query);
		}

		$this->event('sql', $query);
		$return = $this->pdo->exec($query);
		$this->event('query');

		return $return;
	}

	/**
	 * @param mixed $result
	 *
	 * @return mixed
	 */
	public function release($result = null)
	{
		if ($this->extConnectPool) {
			$this->pdo->release();
		}

		if (!PRODUCTION_MODE) {
			if ($result === false &&
				($error = $this->pdo->errorInfo()) &&
				isset($error[0])
			) {
				$result = $error;
				$this->event('error');
			}

			$this->event('done', $result);
		}

		return $result;
	}

	/**
	 * @param $string
	 *
	 * @return string
	 */
	public function quote($string)
	{
		return $this->pdo->quote($string);
	}

	protected function quoteTable($table)
	{
		if (strpos($table, '.') !== false) { // database.table
			return '"' . str_replace('.', '"."' . $this->prefix, $table) . '"';
		}

		return '"' . $this->prefix . $table . '"';
	}

	protected function quoteColumn($string)
	{
		if (strpos($string, '#') === 0) {
			$string = substr($string, 1);
		}

		if ($string === '*') {
			return '*';
		}

		if (($p = strpos($string, '.')) !== false) { // table.column
			if ($string[$p + 1] === '*') {// table.*
				return $this->quoteTable(substr($string, 0, $p)) . '.*';
			}

			return '"' . $this->prefix . str_replace('.', '"."', $string) . '"';
		}

		return '"' . $string . '"';
	}

	protected function columnPush($columns)
	{
		if ($columns === '*') {
			return $columns;
		}

		if (is_string($columns)) {
			$columns = [$columns];
		}

		$stack = [];
		foreach ($columns as $key => $value) {
			preg_match('/([a-zA-Z0-9_\-\.]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $value, $match);

			$special = is_int($key) ? false :
				in_array($key, ['COUNT', 'MAX', 'MIN', 'SUM', 'AVG', 'ROUND'], true);

			if (isset($match[1], $match[2])) {
				$value = $this->quoteColumn($match[1]);
				$stack[] = ($special ? $key . '(' . $value . ')' : $value) . ' AS ' . $this->quoteColumn($match[2]);
			} else {
				$value = $this->quoteColumn($value);
				$stack[] = $special ? $key . '(' . $value . ')' : $value;
			}
		}

		return implode(',', $stack);
	}


	protected function quoteArray($array)
	{
		$temp = [];

		foreach ($array as $value) {
			$temp[] = is_int($value) ? $value : $this->pdo->quote($value);
		}

		return implode(',', $temp);
	}

	protected function innerConjunct($data, $conjunctor, $outerConjunctor)
	{
		$haystack = [];
		foreach ($data as $value) {
			$haystack[] = '(' . $this->dataImplode($value, $conjunctor) . ')';
		}

		return implode($outerConjunctor . ' ', $haystack);
	}

	protected function quoteFn($column, $string)
	{
		return (strpos($column, '#') === 0 && preg_match('/^[A-Z0-9\_]*\([^)]*\)$/', $string)) ? $string : $this->quote($string);
	}

	protected function quoteValue($column, $value)
	{
		switch (gettype($value)) {
			case 'NULL':
				return 'NULL';

			case 'array':
				return $this->quote(Json::encode($value));

			case 'boolean':
				return $value ? '1' : '0';
				break;

			case 'integer':
			case 'double':
				return $value;

			case 'string':
				return $this->quoteFn($column, $value);
		}
	}

	protected function dataImplode($data, $conjunctor)
	{
		$wheres = [];

		foreach ($data as $key => $value) {
			$type = gettype($value);
			if (
				$type === 'array' &&
				preg_match("/^(AND|OR)(\s+#.*)?$/i", $key, $relation)
			) {
				$wheres[] = 0 !== count(array_diff_key($value, array_keys(array_keys($value)))) ?
					'(' . $this->dataImplode($value, ' ' . $relation[1]) . ')' :
					'(' . $this->innerConjunct($value, ' ' . $relation[1], $conjunctor) . ')';
			} else {
				preg_match('/(#?)([\w\.\-]+)(\[(\>|\>\=|\<|\<\=|\!|\<\>|\>\<|\!?~)\])?/i', $key, $match);
				$column = $this->quoteColumn($match[2]);

				if (isset($match[4])) {
					$operator = $match[4];

					if ($operator === '!') {
						switch ($type) {
							case 'NULL':
								$wheres[] = $column . ' IS NOT NULL';
								break;

							case 'array':
								$wheres[] = $column . ' NOT IN (' . $this->quoteArray($value) . ')';
								break;

							case 'integer':
							case 'double':
								$wheres[] = $column . ' != ' . $value;
								break;

							case 'boolean':
								$wheres[] = $column . ' != ' . ($value ? '1' : '0');
								break;

							case 'string':
								$wheres[] = $column . ' != ' . $this->quoteFn($key, $value);
								break;
						}
					}

					if ($operator === '<>' || $operator === '><') {
						if ($type === 'array') {
							if ($operator === '><') {
								$column .= ' NOT';
							}

							if (is_numeric($value[0]) && is_numeric($value[1])) {
								$wheres[] = '(' . $column . ' BETWEEN ' . $value[0] . ' AND ' . $value[1] . ')';
							} else {
								$wheres[] = '(' . $column . ' BETWEEN ' . $this->quote($value[0]) . ' AND ' . $this->quote($value[1]) . ')';
							}
						}
					}

					if ($operator === '~' || $operator === '!~') {
						if ($type !== 'array') {
							$value = [$value];
						}


						$like = [];

						foreach ($value as $item) {
							$item = (string) $item;
							$suffix = $item[-1];

							if ($suffix === '_') {
								$item = substr_replace($item, '%', -1);
							} else if ($suffix === '%') {
								$item = '%' . substr_replace($item, '', -1, 1);
							} else if (preg_match('/^(?!(%|\[|_])).+(?<!(%|\]|_))$/', $item)) {
								$item = '%' . $item . '%';
							}

							$like[] = $column . ($operator === '!~' ? ' NOT' : '') . ' LIKE ' . $this->quoteFn($key, $item);
						}

						$wheres[] = implode(' OR ', $like);
					}

					if (in_array($operator, ['>', '>=', '<', '<='], true)) {
						$condition = $column . ' ' . $operator . ' ';
						if (is_numeric($value)) {
							$condition .= $value;
						} elseif (strpos($key, '#') === 0) {
							$condition .= $this->quoteFn($key, $value);
						} else {
							$condition .= $this->quote($value);
						}

						$wheres[] = $condition;
					}
				} else {
					switch ($type) {
						case 'NULL':
							$wheres[] = $column . ' IS NULL';
							break;

						case 'array':
							$wheres[] = $column . ' IN (' . $this->quoteArray($value) . ')';
							break;

						case 'integer':
						case 'double':
							$wheres[] = $column . ' = ' . $value;
							break;

						case 'boolean':
							$wheres[] = $column . ' = ' . ($value ? '1' : '0');
							break;

						case 'string':
							$wheres[] = $column . ' = ' . $this->quoteFn($key, $value);
							break;
					}
				}
			}
		}

		return implode($conjunctor . ' ', $wheres);
	}

	protected function suffixClause($struct)
	{
		$where = $struct['WHERE'] ?? [];
		foreach (['GROUP', 'ORDER', 'LIMIT', 'HAVING'] as $v) {
			if (isset($struct[$v]) && !isset($where[$v])) {
				$where[$v] = $struct[$v];
			}
		}

		return $this->whereClause($where);
	}

	protected function whereClause($where)
	{
		if (empty($where)) {
			return '';
		}

		$clause = '';
		if (is_array($where)) {
			$whereKeys = array_keys($where);
			$whereAND = preg_grep("/^AND\s*#?$/i", $whereKeys);
			$whereOR = preg_grep("/^OR\s*#?$/i", $whereKeys);

			$single_condition = array_diff_key($where,
				array_flip(['AND', 'OR', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'LIKE', 'MATCH'])
			);

			if ($single_condition !== []) {
				$condition = $this->dataImplode($single_condition, ' AND');
				if ($condition !== '') {
					$clause = ' WHERE ' . $condition;
				}
			}

			if (!empty($whereAND)) {
				$value = array_values($whereAND);
				$clause = ' WHERE ' . $this->dataImplode($where[$value[0]], ' AND');
			}

			if (!empty($whereOR)) {
				$value = array_values($whereOR);
				$clause = ' WHERE ' . $this->dataImplode($where[$value[0]], ' OR');
			}

			if (isset($where['MATCH'])) {
				$MATCH = $where['MATCH'];

				if (is_array($MATCH) && isset($MATCH['columns'], $MATCH['keyword'])) {
					$clause .= ($clause != '' ? ' AND ' : ' WHERE ') . ' MATCH (' . implode(', ', array_map([$this, 'quoteColumn'], $MATCH['columns'])) . ') AGAINST (' . $this->quote($MATCH['keyword']) . ')';
				}
			}

			if (isset($where['GROUP'])) {
				$clause .= ' GROUP BY ' . implode(', ', array_map([$this, 'quoteColumn'], (array) $where['GROUP']));

				if (isset($where['HAVING'])) {
					$clause .= ' HAVING ' . $this->dataImplode($where['HAVING'], ' AND');
				}
			}

			if (isset($where['ORDER'])) {
				$rsort = '/(^[a-zA-Z0-9_\-\.]*)(\s*(DESC|ASC))?/';
				$ORDER = $where['ORDER'];

				if (is_array($ORDER)) {
					$stack = [];

					foreach ($ORDER as $column => $value) {
						if (is_array($value)) {
							$stack[] = 'FIELD(' . $this->quoteColumn($column) . ', ' . $this->quoteArray($value) . ')';
						} else if ($value === 'ASC' || $value === 'DESC') {
							$stack[] = $this->quoteColumn($column) . ' ' . $value;
						} else if ($value === 'asc' || $value === 'desc') {
							$stack[] = $this->quoteColumn($column) . ' ' . strtoupper($value);
						} else if (is_int($column)) {
							preg_match($rsort, $value, $match);
							$stack[] = $this->quoteColumn($match[1]) . ' ' . ($match[3] ?? '');
						}
					}

					$clause .= ' ORDER BY ' . implode($stack, ',');
				} else {
					preg_match($rsort, $ORDER, $match);
					$clause .= ' ORDER BY ' . $this->quoteColumn($match[1]) . ' ' . ($match[3] ?? '');
				}
			}

			if (isset($where['LIMIT'])) {
				$LIMIT = $where['LIMIT'];

				if (is_numeric($LIMIT)) {
					$clause .= ' LIMIT ' . $LIMIT;
				} else if (
					is_array($LIMIT) &&
					is_numeric($LIMIT[0]) &&
					is_numeric($LIMIT[1])
				) {
					if ($this->type === 'pgsql') {
						$clause .= ' OFFSET ' . $LIMIT[0] . ' LIMIT ' . $LIMIT[1];
					} else {
						$clause .= ' LIMIT ' . $LIMIT[0] . ',' . $LIMIT[1];
					}
				}
			}
		} else if ($where !== null) {
			$clause .= ' ' . $where;
		}

		return $clause;
	}

	protected function getTable($struct)
	{
		return $struct['TABLE'] ?? $struct['FROM'] ?? $struct['SELECT'];
	}

	protected function getColumns($struct)
	{
		if (isset($struct['COLUMNS'])) {
			return $struct['COLUMNS'];
		} else if (
			isset($struct['TABLE'], $struct['SELECT']) ||
			isset($struct['FROM'], $struct['SELECT'])
		) {
			return $struct['SELECT'];
		}

		return '*';
	}

	protected function selectContext($struct)
	{
		if (is_string($struct)) {
			$struct = [
				'TABLE' => $struct,
			];
		}

		$table = $this->getTable($struct);
		preg_match('/([a-zA-Z0-9_\-]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $table, $tableMatch);

		if (isset($tableMatch[1], $tableMatch[2])) {
			$table = $this->quoteTable($tableMatch[1]);
			$tableQuery = $this->quoteTable($tableMatch[1]) . ' AS ' . $this->quoteTable($tableMatch[2]);
		} else {
			$table = $this->quoteTable($table);
			$tableQuery = $table;
		}

		if (isset($struct['JOIN'])) {
			$join = [];
			$joinSign = [
				'>' => 'LEFT',
				'<' => 'RIGHT',
				'<>' => 'FULL',
				'><' => 'INNER',
			];

			foreach ($struct['JOIN'] as $sub => $relation) {
				preg_match('/(\[(\<|\>|\>\<|\<\>)\])?([a-zA-Z0-9_\-]*)\s?(\(([a-zA-Z0-9_\-]*)\))?/', $sub, $match);

				if ($match[2] != '' && $match[3] != '') {
					if (is_string($relation)) {
						$relation = 'USING ("' . $relation . '")';
					} else if (is_array($relation)) {
						// For ['column1', 'column2']
						if (isset($relation[0])) {
							$relation = 'USING ("' . implode('", "', $relation) . '")';
						} else {
							$joins = [];

							foreach ($relation as $key => $value) {
								$joins[] = (
									strpos($key, '.') > 0 ?
										// For ['tableB.column' => 'column']
										'"' . $this->prefix . str_replace('.', '"."', $key) . '"' :

										// For ['column1' => 'column2']
										$table . '."' . $key . '"'
									) .
									' = ' .
									$this->quoteTable(isset($match[5]) ? $match[5] : $match[3]) . '."' . $value . '"';
							}

							$relation = 'ON ' . implode(' AND ', $joins);
						}
					}

					$tableName = $this->quoteTable($match[3]) . ' ';
					if (isset($match[5])) {
						$tableName .= 'AS ' . $this->quoteTable($match[5]) . ' ';
					}

					$join[] = $joinSign[$match[2]] . ' JOIN ' . $tableName . $relation;
				}
			}

			$tableQuery .= ' ' . implode(' ', $join);
		}

		$columns = $this->getColumns($struct);
		if (isset($struct['FUN'])) {
			$fn = $struct['FUN'];
			if ($fn == 1) {
				$column = '1';
			} else {
				$column = $fn . '(' . $this->columnPush($columns) . ')';
			}
		} else {
			$column = $this->columnPush($columns);
		}

		return 'SELECT ' . $column . ' FROM ' . $tableQuery . $this->suffixClause($struct);
	}

	/**
	 * @param $table
	 *
	 * @return array
	 */
	public function headers($table)
	{
		$this->event('start', Event::SELECT);
		$sth = $this->query('SELECT * FROM ' . $this->quoteTable($table));

		$headers = [];
		for ($i = 0, $n = $sth->columnCount(); $i < $n; ++$i) {
			$headers[] = $sth->getColumnMeta($i);
		}

		return $this->release($headers);
	}

	/**
	 * @param       $struct
	 * @param int   $fetch
	 * @param mixed $fetchArgs
	 *
	 * @return array|bool
	 */
	public function select($struct, $fetch = PDO::FETCH_ASSOC, $fetchArgs = null)
	{
		$this->event('start', Event::SELECT);
		$query = $this->query(
			$this->selectContext($struct)
		);

		$return = false;
		if ($query) {
			if ($fetchArgs !== null) {
				$return = $query->fetchAll($fetch, $fetchArgs);
			} else {
				$return = $query->fetchAll($fetch);
			}
		}

		return $this->release($return);
	}

	protected function insertContext($table, $datas, $INSERT, $multi = false)
	{
		if (is_array($table)) {
			$datas = $table['VALUES'] ?? $table['SET'];
			$table = $table['FROM'] ?? $table['TABLE'] ?? $table['INSERT'];

			if (is_string($datas)) {
				$INSERT = $datas;
			}
		}

		if (strpos($INSERT, ' ') !== false) {
			$INSERT = explode(' ', trim($INSERT));
			if (count($INSERT) > 3) {
				$INSERT = 'INSERT';
			} else {
				if ($INSERT[0] !== 'REPLACE') {
					$INSERT[0] = 'INSERT';
				}

				if (isset($INSERT[1]) &&
					!in_array($INSERT[1], ['LOW_PRIORITY', 'DELAYED', 'IGNORE'], true)
				) {
					$INSERT[1] = '';
				}

				if (isset($INSERT[2]) &&
					($INSERT[1] === $INSERT[2] || $INSERT[2] !== 'IGNORE')
				) {
					$INSERT[2] = '';
				}

				$INSERT = trim(implode(' ', $INSERT));
			}
		} else if ($INSERT !== 'REPLACE') {
			$INSERT = 'INSERT';
		}

		// Check indexed or associative array
		if (!isset($datas[0])) {
			$datas = [$datas];
		}

		if ($multi) {
			$columns = array_map(
				[$this, 'quoteColumn'],
				array_keys($datas[0])
			);

			$values = [];
			foreach ($datas as $data) {
				$sub = [];
				foreach ($data as $key => $value) {
					$sub[] = $this->quoteValue($key, $value);
				}
				$values[] = '(' . implode(', ', $sub) . ')';
			}

			$sql = $INSERT . ' INTO ' . $this->quoteTable($table) . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $values);
		} else {
			$sql = [];
			foreach ($datas as $data) {
				$values = [];
				$columns = [];

				foreach ($data as $key => $value) {
					$columns[] = $this->quoteColumn($key);
					$values[] = $this->quoteValue($key, $value);
				}

				$sql[] = $INSERT . ' INTO ' . $this->quoteTable($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
			}
		}

		return $sql;
	}

	/**
	 * @param        $table
	 * @param array  $datas
	 * @param string $INSERT
	 *
	 * @return array|mixed
	 */
	public function insert($table, $datas = [], $INSERT = 'INSERT')
	{
		$this->event('start', Event::INSERT);
		$sql = $this->insertContext($table, $datas, $INSERT, false);

		$lastId = [];
		foreach ($sql as $v) {
			$result = $this->exec($v);
			$lastId[] = $this->release(
				$result === false ? $result : $this->pdo->lastInsertId()
			);
		}

		$return = count($lastId) > 1 ? $lastId : $lastId[0];
		$this->lastId = $return;

		return $return;
	}

	/**
	 * @return string|array
	 */
	public function lastInsertId()
	{
		return $this->lastId;
	}

	/**
	 * @param        $table
	 * @param array  $datas
	 * @param string $INSERT
	 *
	 * @return bool|int
	 */
	public function multiInsert($table, $datas = [], $INSERT = 'INSERT')
	{
		$this->event('start', Event::INSERT);
		$sql = $this->insertContext($table, $datas, $INSERT, true);

		return $this->release(
			$this->exec($sql)
		);
	}

	/**
	 * @param       $table
	 * @param array $data
	 * @param null  $where
	 *
	 * @return bool|int
	 */
	public function update($table, $data = [], $where = null)
	{
		$this->event('start', Event::UPDATE);
		if (is_array($table)) {
			$data = $table['SET'] ?? $table['VALUES'];
			$where = $this->suffixClause($table);
			$table = $table['FROM'] ?? $table['TABLE'] ?? $table['UPDATE'];
		} else {
			$where = $this->whereClause($where);
		}

		$fields = [];

		foreach ($data as $key => $value) {
			preg_match('/([\w]+)(\[(\+|\-|\*|\/)\])?/i', $key, $match);

			if (isset($match[3])) {
				if (is_numeric($value)) {
					$fields[] = $this->quoteColumn($match[1]) . ' = ' . $this->quoteColumn($match[1]) . ' ' . $match[3] . ' ' . $value;
				}
			} else {
				$column = $this->quoteColumn($key);
				$fields[] = $column . ' = ' . $this->quoteValue($key, $value);
			}
		}

		return $this->release(
			$this->exec('UPDATE ' . $this->quoteTable($table) . ' SET ' . implode(', ', $fields) . $where)
		);
	}

	/**
	 * @param      $table
	 * @param null $where
	 *
	 * @return bool|int
	 */
	public function delete($table, $where = null)
	{
		$this->event('start', Event::DELETE);
		if (is_array($table)) {
			$where = $this->suffixClause($table);
			$table = $table['FROM'] ?? $table['TABLE'] ?? $table['DELETE'];
		} else {
			$where = $this->whereClause($where);
		}

		return $this->release(
			$this->exec('DELETE FROM ' . $this->quoteTable($table) . $where)
		);
	}

	/**
	 * @param                   $table
	 * @param                   $columns
	 * @param string|array|null $search
	 * @param mixed             $replace
	 * @param array|null        $where
	 *
	 * @return bool|int
	 */
	public function replace($table, $columns, $search = null, $replace = null, $where = null)
	{
		$this->event('start', Event::UPDATE);
		if (is_array($columns)) {
			$replace_query = [];

			foreach ($columns as $column => $replacements) {
				foreach ($replacements as $k => $v) {
					$replace_query[] = $column . ' = REPLACE(' . $this->quoteColumn($column) . ', ' . $this->quote($k) . ', ' . $this->quote($v) . ')';
				}
			}

			$replace_query = implode(', ', $replace_query);
			$where = $search;
		} else if (is_array($search)) {
			$replace_query = [];
			foreach ($search as $k => $v) {
				$replace_query[] = $columns . ' = REPLACE(' . $this->quoteColumn($columns) . ', ' . $this->quote($k) . ', ' . $this->quote($v) . ')';
			}
			$replace_query = implode(', ', $replace_query);
			$where = $replace;
		} else {
			$replace_query = $columns . ' = REPLACE(' . $this->quoteColumn($columns) . ', ' . $this->quote($search) . ', ' . $this->quote($replace) . ')';
		}

		return $this->release(
			$this->exec('UPDATE ' . $this->quoteTable($table) . ' SET ' . $replace_query . $this->whereClause($where))
		);
	}

	/**
	 * @param array          $struct
	 * @param int            $fetch
	 * @param int|array|null $fetchArgs
	 *
	 * @return array|bool
	 */
	public function get($struct, $fetch = PDO::FETCH_ASSOC, $fetchArgs = null)
	{
		$this->event('start', Event::SELECT);
		$query = $this->query(
			$this->selectContext($struct) . ' LIMIT 1'
		);

		$return = false;
		if ($query) {
			if ($fetchArgs === null) {
				$return = $query->fetch($fetch);
			} else {
				$fetchArgs = (array) $fetchArgs;
				array_unshift($fetchArgs, $fetch);

				switch (count($fetchArgs)) {
					case 1:
						$query->setFetchMode($fetchArgs[0]);
						break;
					case 2:
						$query->setFetchMode($fetchArgs[0], $fetchArgs[1]);
						break;
					case 3:
						$query->setFetchMode($fetchArgs[0], $fetchArgs[1], $fetchArgs[2]);
						break;
				}

				$return = $query->fetch($fetch);
			}

			if (is_array($return) && !empty($return)) {
				if (isset($struct['FROM']) || isset($struct['TABLE'])) {
					$column = $struct['SELECT'] ?? $struct['COLUMNS'] ?? null;
				} else {
					$column = $struct['COLUMNS'] ?? null;
				}

				if (is_string($column) && $column !== '*') {
					$return = $return[$column];
				}
			}
		}

		return $this->release($return);
	}

	/**
	 * @param array $struct
	 *
	 * @return bool
	 */
	public function has(array $struct)
	{
		$this->event('start', Event::SELECT);
		if (isset($struct['COLUMNS']) || isset($struct['SELECT'])) {
			unset($struct['COLUMNS'], $struct['SELECT']);
		}
		$struct['FUN'] = 1;

		$query = $this->query(
			'SELECT EXISTS(' . $this->selectContext($struct) . ')'
		);

		$return = false;
		if ($query) {
			$return = $query->fetchColumn();
		}

		return $this->release($return) === '1';
	}

	/**
	 * @param array $struct
	 *
	 * @return bool|int
	 */
	public function count(array $struct)
	{
		$this->event('start', Event::SELECT);
		$struct['FUN'] = 'COUNT';
		$query = $this->query(
			$this->selectContext($struct)
		);

		$return = false;
		if ($query) {
			$return = (int) $query->fetchColumn();
		}

		return $this->release($return);
	}

	/**
	 * @param array $struct
	 *
	 * @return bool|int|string
	 */
	public function max(array $struct)
	{
		$this->event('start', Event::SELECT);
		$struct['FUN'] = 'MAX';
		$query = $this->query(
			$this->selectContext($struct)
		);

		$return = false;
		if ($query) {
			$max = $query->fetchColumn();
			$return = is_numeric($max) ? (int) $max : $max;
		}

		return $this->release($return);
	}

	/**
	 * @param array $struct
	 *
	 * @return bool|int|string
	 */
	public function min(array $struct)
	{
		$this->event('start', Event::SELECT);
		$struct['FUN'] = 'MIN';
		$query = $this->query(
			$this->selectContext($struct)
		);

		$return = false;
		if ($query) {
			$min = $query->fetchColumn();
			$return = is_numeric($min) ? (int) $min : $min;
		}

		return $this->release($return);
	}

	/**
	 * @param array $struct
	 *
	 * @return bool|int
	 */
	public function avg(array $struct)
	{
		$this->event('start', Event::SELECT);
		$struct['FUN'] = 'AVG';
		$query = $this->query(
			$this->selectContext($struct)
		);

		$return = false;
		if ($query) {
			$return = (int) $query->fetchColumn();
		}

		return $this->release($return);
	}

	/**
	 * @param array $struct
	 *
	 * @return bool|int
	 */
	public function sum(array $struct)
	{
		$this->event('start', Event::SELECT);
		$struct['FUN'] = 'SUM';
		$query = $this->query(
			$this->selectContext($struct)
		);

		$return = false;
		if ($query) {
			$return = (int) $query->fetchColumn();
		}

		return $this->release($return);
	}

	/**
	 * @param $table
	 *
	 * @return bool|int
	 */
	public function truncate($table)
	{
		return $this->release(
			$this->exec(
				'TRUNCATE TABLE ' . $table
			)
		);
	}

	/**
	 * @param $actions
	 *
	 * @return bool
	 */
	public function action($actions)
	{
		$result = false;
		if (is_callable($actions, true, $callable)) {
			if (PRODUCTION_MODE) {
				$this->pdo->beginTransaction();
				$result = $callable($this);
			} else {
				$event = new Event($this->type, $this->database, Event::TRANSACTION);

				$this->pdo->beginTransaction();
				$result = $callable($this);

				$event->query();
				$this->event = $event;
			}

			if ($result === false) {
				$this->pdo->rollBack();
			} else {
				$this->pdo->commit();
			}
		}

		return $this->release($result);
	}

	protected function event($type, $arg = null)
	{
		if (PRODUCTION_MODE) {
			return;
		}

		switch ($type) {
			case 'start':
				$this->event = new Event($this->type, $this->database, $arg);
				break;

			case 'sql':
				if ($this->event === null) {
					$this->event('start', Event::QUERY);
					$this->event->sql($arg, false);
				} else {
					$this->event->sql($arg);
				}
				break;

			case 'query':
				if ($this->event !== null) {
					$this->event->query();
				}
				break;

			case 'done':
				if ($this->event !== null) {
					$this->event->done($arg);
					$this->event = null;
				}
				break;

			case 'error':
				if ($this->event !== null) {
					$this->event->error();
				}
				break;
		}
	}

	public function error()
	{
		return $this->pdo->errorInfo();
	}

	public function info()
	{
		$output = [
			'server' => 'SERVER_INFO',
			'driver' => 'DRIVER_NAME',
			'client' => 'CLIENT_VERSION',
			'version' => 'SERVER_VERSION',
			'connection' => 'CONNECTION_STATUS',
		];

		foreach ($output as $key => $value) {
			$output[$key] = $this->pdo->getAttribute(constant('PDO::ATTR_' . $value));
		}

		return $output;
	}
}