<?php

namespace Prototypr;

class Db extends \PDO {

	protected $driver = 'mysql';
	protected $host = 'localhost';
	protected $name = '';
	protected $user = '';
	protected $pass = '';
	protected $options = [];

	protected $num_rows = 0;
	protected $insert_id = 0;
	protected $rows_affected = 0;

	protected $num_queries = 0;
	protected $queries = [];

	public function __construct($dsn, $username=null, $password=null, array $options=null) {
		//set opts?
		if(is_array($dsn)) {
			//loop through array
			foreach($dsn as $k => $v) {
				//property exists?
				if(property_exists($this, $k)) {
					$this->$k = $v;
				}
			}
			//create dsn
			$dsn = $this->driver . ':host=' . $this->host . ';dbname=' . $this->name;
			$username = $this->user;
			$password = $this->pass;
			$options = $this->options;
		}
		//call parent
		parent::__construct($dsn, $username, $password, $options);
		//throw exceptions
		$this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); 
	}

	public function __get($key) {
		//property exists?
		if(property_exists($this, $key)) {
			return $this->$key;
		}
		//not found
		return NULL;
	}

	public function schema($schema) {
		//set vars
		$parts = [];
		//is path?
		if(strpos($schema, ' ') === false) {
			//is file?
			if(strpos($schema, '.') !== false) {
				$files = [ $schema ];
			} else {
				$files = glob(rtrim($schema, '/') . '/*.sql');
			}
			//loop through files
			foreach($files as $file) {
				//has content?
				if($content = file_get_contents($file)) {
					$parts[] = $content;
				}
			}
		} else {
			$parts[] = $schema;
		}
		//loop through parts
		foreach($parts as $part) {
			//loop through queries
			foreach(explode(';', $part) as $query) {
				//trim query
				$query = trim($query);
				//execute query?
				if(!empty($query)) {
					$this->query($query);
				}
			}
		}
		//return
		return true;
	}

	public function cache($method, $query, $params = []) {
		static $_cache = [];
		//set vars
		$s = null;
		//params to array?
		if(!is_array($params)) {
			$params = func_get_args();
			array_shift($params);
			array_shift($params);
		}
		//is statement?
		if(is_object($query)) {
			$s = $query;
			$query = $s->queryString;
			$params = array_merge(isset($s->params) ? $s->params : [], $params);
		}
		//generate cache ID
		$id = $query . $method . http_build_query($params);
		$id = md5(preg_replace('/\s+/', '', $id));
		//execute query?
		if(!isset($_cache[$id])) {
			//prepare statement?
			if(!$s && $params) {
				$s = $this->prepare($query, $params);
			} else if(!$s) {
				$s = $query;
			}
			//run query
			$_cache[$id] = $this->$method($s);
		}
		//return
		return $_cache[$id];
	}

	public function get_var($query, $column_offset = 0, $row_offset = 0) {
		//set vars
		$res = NULL;
		$params = [];
		//has params?
		if(is_array($column_offset)) {
			$params = $column_offset;
			$column_offset = 0;
		}
		//run query
		if($s = $this->query($query, $params)) {
			$res = $s->fetchColumn($column_offset);
		}
		//set num rows
		$this->num_rows = $res ? 1 : 0;
		//return
		return $res ?: NULL;
	}

	public function get_row($query, $output_type = NULL, $row_offset = 0) {
		//set vars
		$res = NULL;
		$params = [];
		//has params?
		if(is_array($output_type)) {
			$params = $output_type;
			$output_type = NULL;
		}
		//run query
		if($s = $this->query($query, $params)) {
			$res = $s->fetch(\PDO::FETCH_OBJ, \PDO::FETCH_ORI_ABS, $row_offset);
		}
		//set num rows
		$this->num_rows = $res ? 1 : 0;
		//return
		return $res ?: NULL;
	}

	public function get_col($query, $column_offset = 0) {
		//set vars
		$res = NULL;
		$params = [];
		//has params?
		if(is_array($column_offset)) {
			$params = $column_offset;
			$column_offset = 0;
		}
		//run query
		if($s = $this->query($query, $params)) {
			$res = $s->fetchAll(\PDO::FETCH_COLUMN, $column_offset);
		}
		//set num rows
		$this->num_rows = $res ? count($res) : 0;
		//return
		return $res ?: [];
	}

	public function get_results($query, $output_type = NULL) {
		//set vars
		$res = NULL;
		$params = [];
		//has params?
		if(is_array($output_type)) {
			$params = $output_type;
			$output_type = NULL;
		}
		//run query
		if($s = $this->query($query, $params)) {
			$res = $s->fetchAll(\PDO::FETCH_OBJ);
		}
		//set num rows
		$this->num_rows = $res ? count($res) : 0;
		//return
		return $res ?: [];
	}

	#[\ReturnTypeWillChange]
	public function prepare($statement, $options = NULL) {
		//format options?
		if(is_string($options) || is_numeric($options)) {
			$options = func_get_args();
			array_shift($options);
		}
		//needs preparing?
		if(!is_object($statement)) {
			//standardise placeholder format
			$statement = preg_replace("/(%[sdf])(\s|\"|\'|\)|\,|$)/i", "?$2", $statement);
			//call parent
			$statement = parent::prepare($statement);
		}
		//cache params?
		if($statement && is_array($options)) {
			$statement->params = isset($statement->params) ? $statement->params : [];
			$statement->params = array_merge($statement->params, $options);
		}
		//return
		return $statement;
	}

	#[\ReturnTypeWillChange]
	public function query($query, $fetchMode = NULL, ...$fetchModeArgs) {
		//set vars
		$time = 0;
		$res = false;
		$params = is_array($fetchMode) ? $fetchMode : [];
		$s = is_object($query) ? $query : $this->prepare($query, $params);
		//execute?
		if(is_object($s)) {
			//merge params
			$params = isset($s->params) ? $s->params : $params;
			//convert params to values?
			if(strpos($s->queryString, '?') !== false && strpos($s->queryString, ':') === false) {
				$params = array_values($params);
			}
			//json encode params
			foreach($params as $k => $v) {
				if(is_array($v)) {
					$params[$k] = json_encode($v);
				}
			}
			//execute
			try {
				$time = microtime(true);
				$s->execute($params);
				$time = microtime(true) - $time;
				//file_put_contents(__DIR__ . '/db.log', $s->queryString . ' - ' . json_encode($params) . "\n", FILE_APPEND);
			} catch(\Exception $e) {
				$e->debug = [ 'Query' => $s->queryString, 'Params' => $params ];
				throw $e;
			}
			//log query
			$this->num_queries++;
			$this->queries[] = [ $s->queryString, $time, $params ];
			//update vars
			$this->num_rows = 0;
			$this->rows_affected = $s->rowCount();
			$this->insert_id = $this->lastInsertId();
		}
		//return
		return $s;
	}

	public function insert($table, array $data, $format = NULL) {
		//set vars
		$params = [];
		$fieldsSql = $this->arr2fields($data);
		$valuesSql = $this->arr2params($data, $params);		
		//create sql
		$sql = "INSERT INTO `$table` ($fieldsSql) VALUES ($valuesSql)";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function replace($table, array $data, $format = NULL) {
		//set vars
		$params = [];
		$fieldsSql = $this->arr2fields($data);
		$valuesSql = $this->arr2params($data, $params);
		//create sql
		$sql = "REPLACE INTO `$table` ($fieldsSql) VALUES ($valuesSql)";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function upsert($table, array $data, $format = NULL) {
		//set vars
		$params = [];
		$fieldsSql = $this->arr2fields($data);
		$valuesSql = $this->arr2params($data);
		$updateSql = $this->arr2where($data, ', ', $params);
		//create sql
		$sql = "INSERT INTO `$table` ($fieldsSql) VALUES ($valuesSql) ON DUPLICATE KEY UPDATE $updateSql";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function update($table, array $data, array $where, $format = NULL, $where_format = NULL) {
		//set vars
		$params = [];
		$setSql = $this->arr2where($data, ', ', $params);
		$whereSql = $this->arr2where($where, ' AND ', $params);
		//create sql
		$sql = "UPDATE `$table` SET $setSql WHERE $whereSql";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function delete($table, array $where, $where_format = NULL) {
		//set vars
		$params = [];
		$whereSql = $this->arr2where($where, ' AND ', $params);
		//create sql
		$sql = "DELETE FROM `$table` WHERE $whereSql";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rows_affected : false;
	}

	protected function arr2fields(array $data) {
		//set vars
		$sql = [];
		//loop through array
		foreach($data as $k => $v) {
			$p = is_numeric($k) ? $v : $k;
			$sql[] = "`$p`";
		}
		//return
		return implode(', ', $sql);
	}

	protected function arr2params(array $data, array &$params = []) {
		//set vars
		$sql = [];
		//loop through array
		foreach($data as $k => $v) {
			$sql[] = ":$k";
			$params[$k] = $v;
		}
		//return
		return implode(', ', $sql);
	}

	protected function arr2where(array $data, $sep, array &$params = []) {
		//set vars
		$sql = [];
		//loop through array
		foreach($data as $k => $v) {
			$p = is_numeric($k) ? '?' : ':' . $k;
			$sql[] = "`$k` = $p";
			$params[$k] = $v;
		}
		//create string
		$sql = implode($sep, $sql);
		//set default?
		if(!$sql && strpos($sep, 'AND') !== false) {
			$sql = "1=1";
		}
		//return
		return $sql;
	}

}