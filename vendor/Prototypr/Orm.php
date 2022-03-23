<?php

namespace Prototypr;

class Orm {

	protected $kernel;
	protected $modelCache = [];
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

	public function create($name, array $opts=[]) {
		//set vars
		$relations = [];
		$idField = 'id';
		$class = $this->formatClass($name);
		//has meta?
		if(method_exists($class, '__meta')) {
			$idField = $class::__meta('id');
			$relations = $class::__meta('relations');
		}
		//check ID cache?
		if(isset($opts[$idField]) && $opts[$idField]) {
			//get cache key
			$idCacheKey = $this->formatKey($class, [ $idField => $opts[$idField] ]);
			//cache hit?
			if(isset($this->modelCache[$idCacheKey])) {
				return $this->modelCache[$idCacheKey];
			}
		}
		//pass kernel
		$opts['kernel'] = $this->kernel;
		//has relations?
		if(!empty($relations)) {
			//create model
			$refClass  = new \ReflectionClass($class);
			$model = $refClass->newInstanceWithoutConstructor();
			//sync relations
			foreach($relations as $prop => $meta) {
				$model->$prop = $this->syncRelation($model, $prop, $meta);
			}
			//call constructor
			$ctor = $refClass->getConstructor();
			$ctor->invokeArgs($model, [ $opts ]);
		} else {
			//create model
			$model = new $class($opts);
		}
		//update ID cache?
		if(isset($model->$idField) && $model->$idField) {
			$idCacheKey = $this->formatKey($class, [ $idField => $model->$idField ]);
			$this->modelCache[$idCacheKey] = $model;
		}
		//return
		return $model;
	}

	public function load($name, $conditions) {
		//set vars
		$data = [];
		$idCacheKey = null;
		$conCacheKey = null;
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
			//generate con cache key
			$conCacheKey = $this->formatKey($class, $conditions);
			//generate ID cache key?
			if(isset($conditions[$idField]) && $conditions[$idField]) {
				$idCacheKey = $this->formatKey($class, [ $idField => $conditions[$idField] ]);
			}
			//check cache keys
			foreach([ $idCacheKey, $conCacheKey ] as $key) {
				//cache hit found?
				if($key && isset($this->modelCache[$key])) {
					return $this->modelCache[$key];
				}
			}
			//query data?
			if(!$data = $this->doQuery($name, $conditions)) {
				//pass conditions
				$data = $conditions;
				//remove id field?
				if(isset($data[$idField])) {
					unset($data[$idField]);
				}
			}
		}
		//create model
		$model = $this->create($name, $data);
		//update con cache?
		if($conditions && $conCacheKey) {
			$this->modelCache[$conCacheKey] = $model;
		}
		//return
		return $model;
	}

	public function loadCollection($name, array $conditions=[]) {
		//set vars
		$collection = [];
		//query data
		$result = $this->doQuery($name, $conditions, true);
		//loop through data
		foreach($result as $row) {
			$collection[] = $this->create($name, $row);
		}
		//return
		return $collection;
	}

	public function hydrate($model, array $conditions) {
		//set vars
		$relations = [];
		$idField = 'id';
		$class = get_class($model);
		//has meta?
		if(method_exists($class, '__meta')) {
			$idField = $class::__meta('id');
			$relations = $class::__meta('relations');
		}
		//query data?
		if(!$data = $this->doQuery($model, $conditions)) {
			//pass conditions
			$data = $conditions;
			//remove id field?
			if(isset($data[$idField])) {
				unset($data[$idField]);
			}
		}
		//set object props
		foreach($data as $key => $val) {
			$model->$key = $val;
		}
		//update ID cache?
		if(isset($model->$idField) && $model->$idField) {
			$idCacheKey = $this->formatKey($class, [ $idField => $model->$idField ]);
			$this->modelCache[$idCacheKey] = $model;
		}
		//update con cache?
		if(!empty($conditions)) {
			$conCacheKey = $this->formatKey($class, $conditions);
			$this->modelCache[$conCacheKey] = $model;
		}
		//sync relations
		foreach($relations as $prop => $meta) {
			$model->$prop = $this->syncRelation($model, $prop, $meta);
		}
		//return
		return $model;
	}

	public function save($model) {
		//set vars
		$data = [];
		$result = null;
		$relations = [];
		$idField = 'id';
		$ignoreFields = [];
		$class = get_class($model);
		$table = $this->dbTable($class);
		//has meta?
		if(method_exists($class, '__meta')) {
			$idField = $class::__meta('id');
			$relations = $class::__meta('relations');
			$ignoreFields = $class::__meta('ignore');
		}
		//valid model?
		if(!property_exists($model, $idField)) {
			throw new \Exception("Model cannot be saved without an ID field: $idField");
		}
		//cache keys
		$changeCacheKey = spl_object_hash($model);
		$modelCacheKey = $this->formatKey($class, [ $idField => $model->$idField ]);
		//get public data
		if($model instanceOf Model && $model->$idField) {
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
			//null zero?
			if($v === 0) {
				$data[$k] = NULL;
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
				continue;
			}
		}
		//anything to save?
		if(!empty($data)) {
			//save event
			$data = $this->kernel->event('orm.save', $data, $model);
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
				$this->modelCache[$modelCacheKey] = $model;
				//reset change cache
				$this->changeCache[$changeCacheKey] = [];
				//sync relations
				foreach($relations as $prop => $meta) {
					$model->$prop = $this->syncRelation($model, $prop, $meta);
				}
			}
		}
		//return
		return ($model->$idField && $result !== false) ? $model->$idField : false;
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
			$namespace = strtolower(explode('\\', $class)[0]);
			$name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
			$table = $this->tableName;
			//check class meta?
			if(method_exists($class, '__meta')) {
				$table = $class::__meta('table') ?: $table;
			}
		}
		//update placeholders
		$table = str_replace([ '{namespace}', '{name}' ], [ $namespace, $name ], $table);
		//return
		return $this->kernel->event('orm.table', $table, $name);
	}

	protected function doQuery($name, array $conditions=[], $collection=false) {
		//is object?
		if(is_object($name)) {
			//get class name
			$class = get_class($name);
			//set conditions?
			if(!$collection && !$conditions) {
				$idField = $this->idField($class);
				$idValue = isset($name->$idField) ? $name->$idField : null;
				$conditions = $idValue ? [ $idField => $idValue ] : [];
			}
			//reset name
			$name = $class;
		}
		//set vars
		$result = [];
		$whereSql = [];
		$table = $this->dbTable($name);
		//check if all conditions empty?
		if(!array_filter($conditions, function($item) { return !empty($item); })) {
			$conditions = [];
		}
		//run query?
		if($collection || $conditions) {
			//create where sql
			foreach($conditions as $k => $v) {
				$conditions[$k] = (string) $v;
				$whereSql[] = "$k = %s";
			}
			//convert to string
			if(!empty($whereSql)) {
				$whereSql = implode(" AND ", $whereSql);
			} else {
				$whereSql = "1=1";
			}
			//select method
			$method = $collection ? 'get_results' : 'get_row';
			//execute query
			$result = (array) $this->kernel->db->cache($method, "SELECT * FROM $table WHERE $whereSql", $conditions) ?: [];
			//decode json
			$result = $this->decodeJson($result);
		}
		//return
		return $result;
	}

	protected function syncRelation($model, $prop, array $meta) {
		//does relation exist?
		if($relation = $model->$prop) {
			//is proxy?
			if(!($relation instanceof Proxy)) {
				//loop through conditions
				foreach($meta['where'] as $k => $v) {
					//is placeholder?
					if($v && $v[0] === ':') {
						//get field
						$field = substr($v, 1);
						//update condition
						$v = $model->$field;
					}
					//set relation prop
					$relation->$k = $v;
				}
			}
		} else {
			//set vars
			$orm = $this;
			//create proxy relation
			$relation = new Proxy(function() use($orm, $model, $prop, $meta) {
				//set vars
				$isCollection = stripos($meta['type'], 'many') !== false;
				$method = $isCollection ? 'loadCollection' : 'load';
				//loop through conditions
				foreach($meta['where'] as $k => $v) {
					//is placeholder?
					if($v && $v[0] === ':') {
						//get field
						$field = substr($v, 1);
						//update condition
						$meta['where'][$k] = $model->$field;
					}
				}
				//load model
				$rel = $orm->$method($meta['model'], $meta['where']);
				//set prop
				$model->$prop = $rel;
				//return
				return $rel;
			});
		}
		//return
		return $relation;
	}

	protected function idField($class) {
		//set vars
		$res = 'id';
		//check class meta?
		if(method_exists($class, '__meta')) {
			$res = $class::__meta('id') ?: $res;
		}
		//return
		return $res;
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

	protected function decodeJson(array $arr) {
		//set vars
		$method = __METHOD__;
		//loop through array
		foreach($arr as $key => $val) {
			//is array?
			if(is_array($val) || is_object($val)) {
				$arr[$key] = $this->$method((array) $val);
			} else if(!empty($val)) {
				$arr[$key] = json_decode($val, true) ?: $val;
			}
		}
		//return
		return $arr;
	}

}