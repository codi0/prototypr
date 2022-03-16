<?php

namespace Prototypr;

class Orm {

	protected $kernel;
	protected $loadCache = [];
	protected $changeCache = [];

	protected $modelClass = '{namespace}\Model\{name}';
	protected $tableName = '{namespace}_{name}';

	protected $namespace = 'App';

	public function __construct(array $opts=[], $merge=true) {
		//set opts
		foreach($opts as $k => $v) {
			//property exists?
			if(property_exists($this, $k)) {
				//is array?
				if($merge && $this->$k === (array) $this->$k) {
					$this->$k = array_merge($this->$k, $v);
				} else {
					$this->$k = $v;
				}
			}
		}
	}

	public function isCached($model) {
		//set vars
		$class = get_class($model);
		$idField = $this->idField($class);
		$idValue = isset($model->$idField) ? $model->$idField : null;
		$cacheKey = $idValue ? $this->formatKey($class, [ $idField => $idValue ]) : null;
		//return
		return $cacheKey && isset($this->loadCache[$cacheKey]);
	}

	public function create($name, array $data=[]) {
		//get class
		$class = $this->formatClass($name);
		//pass kernel
		$data['kernel'] = $this->kernel;
		//create object
		return new $class($data);
	}

	public function load($name, $conditions) {
		//set vars
		$data = [];
		$cacheKeys = [];
		$isEmpty = false;
		$class = $this->formatClass($name);
		$idField = $this->idField($class);
		//convert to array?
		if(!is_array($conditions)) {
			$conditions = $conditions ? [ $idField => $conditions ] : [];
		}
		//check if all conditions empty?
		if(!array_filter($conditions, function($item) { return !empty($item); })) {
			$conditions = [];
		}
		//has conditions?
		if(!empty($conditions)) {
			//set conditions key
			$cacheKeys['conditions'] = $this->formatKey($class, $conditions);
			//set ID key?
			if(isset($conditions[$idField]) && $conditions[$idField]) {
				$cacheKeys['id'] = $this->formatKey($class, [ $idField => $conditions[$idField] ]);
			}
			//loop through keys
			foreach($cacheKeys as $key) {
				//cache hit found?
				if(isset($this->loadCache[$key])) {
					return $this->loadCache[$key];
				}
			}
			//query data
			$data = $this->query($name, $conditions);
		}
		//create model
		$model = $this->create($name, $data);
		//has conditions?
		if(!empty($conditions)) {
			//set ID key?
			if(isset($model->$idField) && $model->$idField) {
				$cacheKeys['id'] = $this->formatKey($class, [ $idField => $model->$idField ]);
			}
			//loop through cache keys
			foreach($cacheKeys as $key) {
				$this->loadCache[$key] = $model;
			}
		}
		//return
		return $model;
	}

	public function save($model) {
		//set vars
		$data = [];
		$result = null;
		$class = get_class($model);
		$idField = $this->idField($class);
		$table = $this->dbTable($class);
		$ignoreFields = isset($class::$dbIgnore) ? $class::$dbIgnore : [];
		//valid model?
		if(!property_exists($model, $idField)) {
			throw new \Exception("Model cannot be saved without an ID field: $idField");
		}
		//cache keys
		$changeCacheKey = spl_object_hash($model);
		$loadCacheKey = $this->formatKey($class, [ $idField => $model->$idField ]);
		//get public data
		if($model instanceOf Model) {
			//use change cache?
			if(isset($this->changeCache[$changeCacheKey])) {
				$data = $this->changeCache[$changeCacheKey];
			}
		} else if(method_exists($model, 'toArray')) {
			//data as array
			$data = $model->toArray();
		} else {
			//use reflection
			$ref = new \ReflectionObject($model);
			//loop through props
			foreach($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
				//get name
				$name = $prop->getName();
				//add to array?
				if(!$prop->isStatic()) {
					$data[$name] = $prop->getValue($model);
				}
			}
		}
		//filter data
		foreach($data as $k => $v) {
			//remove from array?
			if($k === $idField || in_array($k, $ignoreFields) || is_object($v)) {
				unset($data[$k]);
				continue;
			}
			//is bool?
			if(is_bool($v)) {
				$data[$k] = $v ? 1 : 0;
				continue;
			}
			//is array?
			if(is_array($v)) {
				$data[$k] = json_encode($v);
			}
		}
		//anything to save?
		if(!empty($data)) {
			//save data
			if($model->$idField) {
				//update query
				$result = $this->kernel->db->update($table, $data, [ $idField => $model->$idField ]);
			} else {
				//insert query
				$result = $this->kernel->db->insert($table, $data);
				//cache insert ID?
				if($result !== false) {
					$model->$idField = $this->kernel->db->insert_id;
				}
			}
			//update caches?
			if($result !== false) {
				//update load cache
				$this->loadCache[$loadCacheKey] = $model;
				//reset change cache
				$this->changeCache[$changeCacheKey] = [];
			}
		}
		//return
		return ($model->$idField && $result !== false) ? $model->$idField : false;
	}

	public function query($name, array $conditions=[]) {
		//is object?
		if(is_object($name)) {
			//get class name
			$class = get_class($name);
			//set conditions?
			if(empty($conditions)) {
				$idField = $this->idField($class);
				$idValue = isset($name->$idField) ? $name->$idField : null;
				$conditions = $idValue ? [ $idField => $idValue ] : [];
			}
			//reset name
			$name = $class;
		}
		//set vars
		$row = [];
		$whereSql = [];
		$table = $this->dbTable($name);
		//check if all conditions empty?
		if(!array_filter($conditions, function($item) { return !empty($item); })) {
			$conditions = [];
		}
		//has conditions?
		if(!empty($conditions)) {
			//create where sql
			foreach($conditions as $k => $v) {
				$conditions[$k] = (string) $v;
				$whereSql[] = "$k = %s";
			}
			//convert to string
			$whereSql = implode(" AND ", $whereSql);
			//execute query
			$row = $this->kernel->db->cache('get_row', "SELECT * FROM $table WHERE $whereSql", $conditions) ?: [];
			//check for json
			foreach($row as $key => $val) {
				//decode value?
				if(!empty($val)) {
					$row->$key = json_decode($val, true) ?: $val;
				}
			}
		}
		//return
		return (array) $row;
	}

	public function onChange($model, $key, $val) {
		//get model hash
		$hash = spl_object_hash($model);
		//create array?
		if(!isset($this->changeCache[$hash])) {
			$this->changeCache[$hash] = [];
		}
		//log change
		$this->changeCache[$hash][$key] = $val;
	}

	public function dbTable($name) {
		//is table?
		if(strpos($name, '_') !== false) {
			$table = $name;
			$namespace = $this->namespace;
		} else {
			//set vars
			$class = $this->formatClass($name);
			$namespace = explode('\\', $class)[0];
			$name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
			$table = $this->tableName;
			//has class prop?
			if(isset($class::$dbTable) && $class::$dbTable) {
				$table = $class::$dbTable;
			}
		}
		//update placeholders
		$table = str_replace([ '{namespace}', '{name}' ], [ $namespace, $name ], $table);
		//return
		return $this->kernel->event('orm.table', $table, $name);
	}

	protected function formatClass($name) {
		//is class?
		if(strpos($name, '\\') !== false) {
			$class = $name;
		} else {
			$name = str_replace('_', '', ucwords($name, '_'));
			$class = $this->modelClass;
		}
		//update placeholders
		$class = str_replace([ '{namespace}', '{name}' ], [ $this->namespace, $name ], $class);
		//return
		return $this->kernel->event('orm.class', $class, $name);
	}

	protected function formatKey($class, $data) {
		//loop through data
		foreach($data as $k => $v) {
			if(is_numeric($v)) {
				$data[$k] = (string) $v;
			}
		}
		//return
		return md5($class . json_encode($data));
	}

	protected function idField($class) {
		//has ID field?
		if(isset($class::$idField) && $class::$idField) {
			return $class::$idField;
		}
		//use default
		return 'id';
	}

}