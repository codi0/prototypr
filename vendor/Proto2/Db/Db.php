<?php

namespace Proto2\Db;

class Db {

	public $numRows = 0;
	public $insertId = 0;
	public $rowsAffected = 0;
	public $queries = [];

	protected $conns = [];
	protected $driver = 'mysql';
	protected $connClass = 'Pdo';
	protected $statementClass = 'Proto2\Db\Statement';

	protected $lastConn;
	protected $lastTable = '';

	public function __construct(array $opts=[], $merge=true) {
		//set opts
		foreach($opts as $k => $v) {
			//property exists?
			if(property_exists($this, $k)) {
				//is array?
				if(!$merge || !is_array($this->$k) || !is_array($v)) {
					$this->$k = $v;
					continue;
				}
				//loop through array
				foreach($v as $a => $b) {
					$this->$k[$a] = $b;
				}
			}
		}
		//get conns
		$conns = $this->conns;
		$this->conns = [];
		//use opts as conn?
		if(!$conns && isset($opts['host'])) {
			$conns[] = $opts;
		}
		//loop through conns
		foreach($conns as $conn) {
			$this->addConn($conn);
		}
	}

	public function getConns() {
		//set vars
		$res = [];
		//loop through conns
		foreach($this->conns as $conn) {
			//is connected?
			if(!$conn['conn']) {
				continue;
			}
			//add to array
			$res[] = [
				'conn' => $conn['conn'],
				'queries' => $conn['queries'],
			];
		}
		//return
		return $res;
	}

	public function getConn($query='') {
		//set vars
		$index = null;
		$table = null;
		$isWrite = false;
		//check query?
		if(is_object($query)) {
			//is statement object?
			if(isset($query->queryString)) {
				$query = $query->queryString;
			} else {
				$this->lastConn = $query;
				$query = '';
			}
		} else {
			$query = is_string($query) ? $query : '';
		}
		//check query?
		if($query) {
			//is write query
			$isWrite = $this->isWriteQuery($query);
			//get table from query
			$table = $this->getTableFromQuery($query);
		}
		//check connections?
		if($query || !$this->lastConn) {
			//loop through connections
			foreach($this->conns as $key => $val) {
				//set vars
				$match = false;
				//skip read?
				if(!$val['read'] && !$isWrite) {
					continue;
				}
				//skip read?
				if(!$val['write'] && $isWrite) {
					continue;
				}
				//check table?
				if($table) {
					//fallback conn?
					if(!$val['tables']) {
						//has index?
						if($index === null) {
							$index = $key;
						}
						//next
						continue;
					}
					//loop through tables
					foreach($val['tables'] as $t) {
						//match found?
						if(stripos($table, $t) === 0) {
							$match = true;
							break;
						}
					}
					//skip?
					if(!$match) {
						continue;
					}
				}
				//stop here
				$index = $key;
				break;
			}
		}
		//has index?
		if($index !== null) {
			//get data
			$data = $this->conns[$index];
			//create connection?
			if(!$data['conn']) {
				$class = $this->connClass;
				$data['conn'] = new $class($data['opts']['dsn'], $data['opts']['user'], $data['opts']['pass'], $data['opts']['options']);
			}
			//cache conn
			$this->lastConn = $this->conns[$index]['conn'] = $data['conn'];
		}
		//has connection?
		if(!$this->lastConn) {
			throw new \Exception("No database connection found");
		}
		//return
		return $this->lastConn;
	}

	public function addConn($conn, array $tables=[], $read=true, $write=true) {
		//set vars
		$opts = [];
		//format opts?
		if(is_array($conn)) {
			//set defaults
			$opts = array_merge([
				'dsn' => '',
				'driver' => $this->driver,
				'host' => 'localhost',
				'user' => '',
				'pass' => '',
				'name' => '',
				'options' => [],
			], $conn);
			//reset conn
			$conn = null;
			//set dsn?
			if(!$opts['dsn']) {
				$opts['dsn'] = $opts['driver'] . ':host=' . $opts['host'] . ';dbname=' . $opts['name'];
			}
			//check vars
			foreach([ 'conn', 'tables', 'read', 'write' ] as $var) {
				if(isset($opts[$var])) {
					$$var = $opts[$var];
					unset($opts[$var]);
				}
			}
		}
		//add connection
		$this->conns[] = [
			'opts' => $opts,
			'conn' => $conn,
			'tables' => $tables,
			'read' => $read,
			'write' => $write,
			'queries' => [],
			'cache' => [],
		];
		//chain it
		return $this;
	}

	public function prepare($query, array $params=[]) {
		//create statement?
		if(!is_object($query)) {
			//standardise placeholder format
			$query = preg_replace("/(%[sdf])(\s|\"|\'|\)|\,|$)/i", "?$2", $query);
			//get connection
			$conn = $this->getConn($query);
			//prepare query?
			if(method_exists($conn, 'prepare')) {
				ob_start();
				$query = $conn->prepare($query) ?: $query;
				ob_get_clean();
			}
			//wrap query?
			if(!is_object($query)) {
				$class = $this->statementClass;
				$query = new $class($conn, $query);
			}
		}
		//cache params?
		if(!empty($params)) {
			$query->params = $params;
		}
		//return
		return $query;
	}

	public function query($query, array $params=[]) {
		//set vars
		$res = false;
		//prepare query
		$s = $this->prepare($query, $params);
		//update params?
		if(isset($query->params) && $query->params) {
			$params = $query->params;
		}
		//convert params to values?
		if(strpos($s->queryString, '?') !== false && strpos($s->queryString, ':') === false) {
			$params = array_values($params);
		}
		//format params
		foreach($params as $k => $v) {
			if(is_null($v)) {
				$params[$k] = '';
			} else if(is_boolean($v)) {
				$params[$k] = $v ? 1 : 0;
			} else if(is_array($v) || is_object($v)) {
				$params[$k] = json_encode((array) $v);
			}
		}
		//execute
		$start = microtime(true);
		$res = $s->execute($params);
		$time = number_format(microtime(true) - $start, 6);
		//get connection
		$conn = $this->getConn();
		$connKey = $this->getConnKey($conn);
		//log query
		$this->conns[$connKey]['queries'][] = [ $s->queryString, $time, $params ];
		//update vars
		$this->numRows = 0;
		$this->insertId = 0;
		$this->rowsAffected = $s->rowCount();
		$this->queries = $this->conns[$connKey]['queries'];
		//get insert ID
		if(method_exists($conn, 'lastInsertId')) {
			$this->insertId = $conn->lastInsertId();
		} else if(isset($conn->insertId)) {
			$this->insertId = $conn->insertId;
		} else if(isset($conn->insert_id)) {
			$this->insertId = $conn->insert_id;
		}
		//return
		return $res ? $s : false;
	}

	public function beginTransaction() {
		//get connection
		$conn = $this->getConn();
		//has method?
		if(method_exists($conn, 'beginTransaction')) {
			return $conn->beginTransaction();
		}
		//not found
		return true;
	}

	public function commit() {
		//get connection
		$conn = $this->getConn();
		//has method?
		if(method_exists($conn, 'commit')) {
			return $conn->commit();
		}
		//not found
		return true;
	}

	public function rollback() {
		//get connection
		$conn = $this->getConn();
		//has method?
		if(method_exists($conn, 'rollback')) {
			return $conn->rollback();
		}
		//not found
		return true;
	}

	public function getVar($query, array $params=[]) {
		//set vars
		$res = NULL;
		//add limit?
		if(is_string($query) && stripos($query, ' LIMIT ') === false) {
			$query .= ' LIMIT 1';
		}
		//run query
		if($s = $this->query($query, $params)) {
			//fetch column
			$res = $s->fetchColumn();
			//set to null?
			if($res === false) {
				$res = NULL;
			}
		}
		//set num rows
		$this->numRows = $res ? 1 : 0;
		//return
		return $res;
	}

	public function getRow($query, array $params=[]) {
		//set vars
		$res = NULL;
		//add limit?
		if(is_string($query) && stripos($query, ' LIMIT ') === false) {
			$query .= ' LIMIT 1';
		}
		//run query
		if($s = $this->query($query, $params)) {
			//fetch row
			$res = $s->fetch(\PDO::FETCH_ASSOC);
			//set to null?
			if($res === false) {
				$res = NULL;
			}
		}
		//set num rows
		$this->numRows = $res ? 1 : 0;
		//return
		return $res;
	}

	public function getCol($query, array $params=[]) {
		//set vars
		$res = NULL;
		//run query
		if($s = $this->query($query, $params)) {
			$res = $s->fetchAll(\PDO::FETCH_COLUMN, 0);
		}
		//set num rows
		$this->numRows = $res ? count($res) : 0;
		//return
		return $res ?: [];
	}

	public function getResults($query, array $params=[]) {
		//set vars
		$res = NULL;
		//run query
		if($s = $this->query($query, $params)) {
			$res = $s->fetchAll(\PDO::FETCH_ASSOC);
		}
		//set num rows
		$this->numRows = $res ? count($res) : 0;
		//return
		return $res ?: [];
	}

	public function insert($table, array $data) {
		//set vars
		$params = [];
		$fieldsSql = $this->arr2fields($data);
		$valuesSql = $this->arr2params($data, $params);		
		//create sql
		$sql = "INSERT INTO `$table` ($fieldsSql) VALUES ($valuesSql)";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rowsAffected : false;
	}

	public function replace($table, array $data) {
		//set vars
		$params = [];
		$fieldsSql = $this->arr2fields($data);
		$valuesSql = $this->arr2params($data, $params);
		//create sql
		$sql = "REPLACE INTO `$table` ($fieldsSql) VALUES ($valuesSql)";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rowsAffected : false;
	}

	public function upsert($table, array $data) {
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
		return $s ? $this->rowsAffected : false;
	}

	public function update($table, array $data, array $where) {
		//set vars
		$params = [];
		$setSql = $this->arr2where($data, ', ', $params);
		$whereSql = $this->arr2where($where, ' AND ', $params);
		//create sql
		$sql = "UPDATE `$table` SET $setSql WHERE $whereSql";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rowsAffected : false;
	}

	public function delete($table, array $where) {
		//set vars
		$params = [];
		$whereSql = $this->arr2where($where, ' AND ', $params);
		//create sql
		$sql = "DELETE FROM `$table` WHERE $whereSql";
		//execute query
		$s = $this->query($sql, $params);
		//return
		return $s ? $this->rowsAffected : false;
	}

	public function cache($method, $query, array $params=[]) {
		//set vars
		$s = null;
		//is statement?
		if(is_object($query)) {
			$s = $query;
			$query = $s->queryString;
			$params = array_merge(isset($s->params) ? $s->params : [], $params);
		}
		//get conn
		$conn = $this->getConn($query);
		$connKey = $this->getConnKey($conn);
		//generate cache ID
		$id = $query . $method . http_build_query($params);
		$id = md5(preg_replace('/\s+/', '', $id));
		//execute query?
		if(!isset($this->conns[$connKey]['cache'][$id])) {
			//prepare statement?
			if(!$s && $params) {
				$s = $this->prepare($query, $params);
			} else if(!$s) {
				$s = $query;
			}
			//run query
			$this->conns[$connKey]['cache'][$id] = $this->$method($s);
		}
		//return
		return $this->conns[$connKey]['cache'][$id];
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

	protected function getConnKey($conn) {
		//loop through array
		foreach($this->conns as $key => $val) {
			//match found?
			if($val['conn'] === $conn) {
				return $key;
			}
		}
		//not found
		return null;
	}

	protected function isWriteQuery($query) {
		//trim query
		$query = ltrim($query, "\r\n\t (");
		//is select query?
		return !preg_match('/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\s/i', $query);	
	}

	protected function getTableFromQuery($query) {
		//strip unwanted chars
		$query = rtrim($query, ';/-#');
		$query = ltrim($query, "\t (");
		$query = preg_replace('/\((?!\s*select)[^(]*?\)/is', '()', substr($query, 0, 1500));
		//SELECT FOUND_ROWS() refers to the previous SELECT query
		if(preg_match('/^\s*SELECT.*?\s+FOUND_ROWS\(\)/is', $query)) {
			return $this->lastTable;
		}
		//SELECT FROM information_schema
		if(preg_match(
			'/^\s*'
				. 'SELECT.*?\s+FROM\s+`?information_schema`?\.'
				. '.*\s+TABLE_NAME\s*=\s*["\']([\w-]+)["\']/is',
			$query,
			$maybe
		)) {
			$this->lastTable = $maybe[1];
			return $this->lastTable;
		}
		//transaction support
		if(preg_match(
			'/^\s*'
				. '(?:START\s+TRANSACTION|COMMIT|ROLLBACK)\s*\/[*]\s*IN_TABLE\s*=\s*'
				. "'?([\w-]+)'?/is",
			$query,
			$maybe
		)) {
			$this->lastTable = $maybe[1];
			return $this->lastTable;
		}
		//common queries
		if(preg_match(
			'/^\s*(?:'
				. 'SELECT.*?\s+FROM'
				. '|INSERT(?:\s+LOW_PRIORITY|\s+DELAYED|\s+HIGH_PRIORITY)?(?:\s+IGNORE)?(?:\s+INTO)?'
				. '|REPLACE(?:\s+LOW_PRIORITY|\s+DELAYED)?(?:\s+INTO)?'
				. '|UPDATE(?:\s+LOW_PRIORITY)?(?:\s+IGNORE)?'
				. '|DELETE(?:\s+LOW_PRIORITY|\s+QUICK|\s+IGNORE)*(?:.+?FROM)?'
			. ')\s+((?:[0-9a-zA-Z$_.`-]|[\xC2-\xDF][\x80-\xBF])+)/is',
			$query,
			$match
		)) {
			$this->lastTable = str_replace('`', '', $match[1]);
			return $this->lastTable;
		}
		//SHOW TABLE STATUS and SHOW TABLES
		if(preg_match('/^\s*SHOW\s+(?:TABLE\s+STATUS|(?:FULL\s+)?TABLES).+WHERE\s+Name\s*=\s*("|\')((?:[0-9a-zA-Z$_.-]|[\xC2-\xDF][\x80-\xBF])+)\\1/is', $query, $match)) {
			$this->lastTable = $match[2];
			return $this->lastTable;
		}
		//SHOW TABLE LIKE
		if(preg_match('/^\s*SHOW\s+(?:TABLE\s+STATUS|(?:FULL\s+)?TABLES)\s+(?:WHERE\s+Name\s+)?LIKE\s*("|\')((?:[\\\\0-9a-zA-Z$_.-]|[\xC2-\xDF][\x80-\xBF])+)%?\\1/is', $query, $match)) {
			$this->lastTable = str_replace('\\_', '_', $match[2]);
			return $this->lastTable;
		}
		//other table queries
		if(preg_match(
			'/^\s*(?:'
				. '(?:EXPLAIN\s+(?:EXTENDED\s+)?)?SELECT.*?\s+FROM'
				. '|DESCRIBE|DESC|EXPLAIN|HANDLER'
				. '|(?:LOCK|UNLOCK)\s+TABLE(?:S)?'
				. '|(?:RENAME|OPTIMIZE|BACKUP|RESTORE|CHECK|CHECKSUM|ANALYZE|REPAIR).*\s+TABLE'
				. '|TRUNCATE(?:\s+TABLE)?'
				. '|CREATE(?:\s+TEMPORARY)?\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?'
				. '|ALTER(?:\s+IGNORE)?\s+TABLE'
				. '|DROP\s+TABLE(?:\s+IF\s+EXISTS)?'
				. '|CREATE(?:\s+\w+)?\s+INDEX.*\s+ON'
				. '|DROP\s+INDEX.*\s+ON'
				. '|LOAD\s+DATA.*INFILE.*INTO\s+TABLE'
				. '|(?:GRANT|REVOKE).*ON\s+TABLE'
				. '|SHOW\s+(?:.*FROM|.*TABLE)'
			. ')\s+\(*\s*((?:[0-9a-zA-Z$_.`-]|[\xC2-\xDF][\x80-\xBF])+)\s*\)*/is',
			$query,
			$match
		)) {
			$this->lastTable = str_replace('`', '', $match[1]);
			return $this->lastTable;
		}
		//not found
		return null;
	}

}