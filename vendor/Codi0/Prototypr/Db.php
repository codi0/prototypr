<?php

namespace Codi0\Prototypr;

class Db extends \PDO {

	protected $insert_id = 0;
	protected $rows_affected = 0;

	protected $num_queries = 0;
	protected $queries = [];

	protected $cache = [];

	public function __get($key) {
		return isset($this->$key) ? $this->$key : null;
	}

	public function get_var($query, $column_offset = 0, $row_offset = 0) {
		//set vars
		$res = NULL;
		//run query
		if($s = $this->query($query)) {
			$res = $s->fetchColumn($column_offset);
		}
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
		//return
		return $res ?: [];
	}

	public function cache($method, $query, array $params=[], $expiry = NULL) {
		//set cache ID
		$cacheId = $query;
		//add query params
		foreach($params as $k => $v) {
			$cacheId .= $k . $v;
		}
		//hash key
		$cacheId = md5($cacheId);
		//execute query?
		if(!isset($this->cache[$cacheId])) {
			$this->cache[$cacheId] = $this->$method($query, $params);
		}
		//return
		return $this->cache[$cacheId];
	}

	public function query($query, $fetchMode = NULL, ...$fetchModeArgs) {
		//execute query
		if(is_object($query)) {
			$s = $fetchMode ? $query->execute($fetchMode) : $query->execute();
		} else if(is_array($fetchMode)) {
			$s = $this->prepare($query)->execute($fetchMode);
		} else {
			$s = $fetchMode ? parent::query($query, $fetchMode) : parent::query($query);
		}
		//update vars?
		if(is_object($s)) {
			$this->num_queries++;
			$this->queries[] = $s->queryString;
			$this->rows_affected = $s->rowCount();
			$this->insert_id = $this->lastInsertId();
		}
		//return
		return $s;
	}

	public function insert($table, array $data, $format = NULL) {
		//set vars
		$fields = array_keys($data);
		$values = array_map(function($i) { return ':' . $i; }, $fields);
		//execute query
		$s = $this->query("INSERT INTO " . $table . " (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")", $data);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function replace($table, array $data, $format = NULL) {
		//set vars
		$fields = array_keys($data);
		$values = array_map(function($i) { return ':' . $i; }, $fields);
		//execute query
		$s = $this->query("REPLACE INTO " . $table . " (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")", $data);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function update($table, array $data, array $where, $format = NULL, $where_format = NULL) {
		//set vars
		$params = [];
		$setSql = [];
		$whereSql = [ "1=1" ];
		//loop through data
		foreach($data as $k => $v) {
			$setSql[] = "$k = :$k";
			$params[$k] = $v;
		}
		//loop through where
		foreach($where as $k => $v) {
			$whereSql[] = "$k = :$k";
			$params[$k] = $v;
		}
		//execute query
		$s = $this->query("UPDATE " . $table . " SET " . implode(', ', $setSql) . " WHERE " . implode(' AND ', $whereSql), $params);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function delete($table, array $where, $where_format = NULL) {
		//set vars
		$whereSql = [ "1=1" ];
		//loop through where
		foreach($where as $k => $v) {
			$whereSql[] = "$k = :$k";
		}
		//execute query
		$s = $this->query("DELETE FROM " . $table . " WHERE " . implode(' AND ', $whereSql), $where);
		//return
		return $s ? $this->rows_affected : false;
	}

	public function loadSchema($schema) {
		//load file?
		if(strpos($schema, ' ') === false) {
			$schema = file_get_contents($schema);
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
	}

}