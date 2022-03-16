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
		//load file?
		if(strpos($schema, ' ') === false) {
			$schema = file_get_contents($path);
		}
		//loop through queries
		foreach(explode(';', $schema) as $query) {
			//trim query
			$query = trim($query);
			//execute query?
			if(!empty($query)) {
				$this->query($query);
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
			$s = $s ?: $this->prepare($query, $params);
			$_cache[$id] = $this->$method($s);
		}
		//return
		return $_cache[$id];
	}

	public function get_var($query, $column_offset = 0, $row_offset = 0) {
		//set vars
		$res = NULL;
		//run query
		if($s = $this->query($query)) {
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
		//run query
		if($s = $this->query($query)) {
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
		//run query
		if($s = $this->query($query)) {
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
		//run query
		if($s = $this->query($query)) {
			$res = $s->fetchAll(\PDO::FETCH_OBJ);
		}
		//set num rows
		$this->num_rows = $res ? count($res) : 0;
		//return
		return $res ?: [];
	}

	public function prepare($statement, $options = NULL) {
		//format options?
		if(is_string($options) || is_numeric($options)) {
			$options = func_get_args();
			array_shift($options);
		}
		//needs preparing?
		if(!is_object($statement)) {
			//standardise placeholder format
			$statement = preg_replace("/(%[sdf])(\s|\"|\'|\)|$)/i", "?$2", $statement);
			//call parent
			$statement = parent::prepare($statement);
		}
		//cache params?
		if($statement && is_array($options)) {
			$statement->params = array_merge(isset($statement->params) ? $statement->params : [], $options);
		}
		//return
		return $statement;
	}

	public function query($query, $fetchMode = NULL, ...$fetchModeArgs) {
		//set vars
		$res = false;
		$params = is_array($fetchMode) ? $fetchMode : [];
		$s = is_object($query) ? $query : $this->prepare($query, $params);
		//execute?
		if(is_object($s)) {
			//merge params
			$params = array_merge(isset($s->params) ? $s->params : [], $params);
			//convert params to values?
			if(strpos($s->queryString, '?') !== false && strpos($s->queryString, ':') === false) {
				$params = array_values($params);
			}
			//execute
			try {
				$s->execute($params);
			} catch(\Exception $e) {
				//echo '<p>' . $s->queryString . '</p>' . "\n";
				//print_r($params);
				throw $e;
			}
			//log query
			$this->num_queries++;
			$this->queries[] = $s->queryString;
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
		$fields = array_keys($data);
		$fieldsSql = implode(', ', $fields);
		$valuesSql = implode(', ', array_map(function($i) { return ':' . $i; }, $fields));
		$sql = "INSERT INTO $table ($fieldsSql) VALUES ($valuesSql)";
		//execute query
		$s = $this->query($sql, $data);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function replace($table, array $data, $format = NULL) {
		//set vars
		$fields = array_keys($data);
		$fieldsSql = implode(', ', $fields);
		$valuesSql = implode(', ', array_map(function($i) { return ':' . $i; }, $fields));
		$sql = "REPLACE INTO $table ($fieldsSql) VALUES ($valuesSql)";
		//execute query
		$s = $this->query($sql, $data);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function update($table, array $data, array $where, $format = NULL, $where_format = NULL) {
		//set vars
		$params = [];
		$setSql = $this->params2sql($data, ', ', $params);
		$whereSql = $this->params2sql($where, ' AND ', $params);
		$sql = "UPDATE $table SET $setSql WHERE $whereSql";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function delete($table, array $where, $where_format = NULL) {
		//set vars
		$whereSql = $this->params2sql($where, ' AND ');
		$sql = "DELETE FROM $table WHERE $whereSql";
		//execute query
		$s = $this->query($sql, $where);
		//return
		return $s ? $this->rows_affected : false;
	}

	protected function params2sql(array $params, $sep, array &$output = []) {
		//set vars
		$sql = [];
		//loop through array
		foreach($params as $k => $v) {
			$p = is_numeric($k) ? '?' : ':' . $k;
			$sql[] = "$k = $p";
			$output[$k] = $v;
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