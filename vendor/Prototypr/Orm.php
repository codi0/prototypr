<?php

namespace Prototypr;

class Orm {

	use ConstructTrait;

	protected $modelCache = [];
	protected $changeCache = [];

	protected $modelClass = '{namespace}\Model\{name}';
	protected $tableName = '{namespace}_{name}';

	protected $namespace = 'App';

	protected function onConstruct(array $opts) {
		//use custom namesapce?
		if($ns = $this->kernel->config('namespace')) {
			$this->namespace = $ns;
		}
	}

	public function create($name, array $opts=[]) {
		//set vars
		$class = $this->formatClass($name);
		$meta = $this->classMeta($class);
		//check ID cache?
		if(isset($opts[$meta['id']]) && $opts[$meta['id']]) {
			//get cache key
			$idCacheKey = $this->formatKey($class, [ $meta['id'] => $opts[$meta['id']] ]);
			//cache hit?
			if(isset($this->modelCache[$idCacheKey])) {
				return $this->modelCache[$idCacheKey];
			}
		}
		//pass kernel
		$opts['kernel'] = $this->kernel;
		//has relations?
		if($meta['relations']) {
			//create model
			$refClass  = new \ReflectionClass($class);
			$model = $refClass->newInstanceWithoutConstructor();
			//sync relations
			foreach($meta['relations'] as $k => $v) {
				$model->$k = $this->syncRelation($model, $k, $v);
			}
			//call constructor
			$ctor = $refClass->getConstructor();
			$ctor->invokeArgs($model, [ $opts ]);
		} else {
			//create model
			$model = new $class($opts);
		}
		//update ID cache?
		if($model->{$meta['id']}) {
			$idCacheKey = $this->formatKey($class, [ $meta['id'] => $model->{$meta['id']} ]);
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
		$meta = $this->classMeta($class);
		//convert to array?
		if(!is_array($conditions)) {
			$conditions = $conditions ? [ $meta['id'] => $conditions ] : [];
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
			if(isset($conditions[$meta['id']]) && $conditions[$meta['id']]) {
				$idCacheKey = $this->formatKey($class, [ $meta['id'] => $conditions[$meta['id']] ]);
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
				if(isset($data[$meta['id']])) {
					unset($data[$meta['id']]);
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

	public function loadCollection($name, array $conditions=[], $wrap=true) {
		//wrap?
		if($wrap) {
			return new ModelCollection([
				'name' => $name,
				'conditions' => $conditions,
				'orm' => $this,
			]);
		}
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
		$class = get_class($model);
		$meta = $this->classMeta($class);
		//query data?
		if(!$data = $this->doQuery($class, $conditions)) {
			//pass conditions
			$data = $conditions;
			//remove id field?
			if(isset($data[$meta['id']])) {
				unset($data[$meta['id']]);
			}
		}
		//set object props
		foreach($data as $key => $val) {
			$model->$key = $val;
		}
		//update ID cache?
		if($model->{$meta['id']}) {
			$idCacheKey = $this->formatKey($class, [ $meta['id'] => $model->{$meta['id']} ]);
			$this->modelCache[$idCacheKey] = $model;
		}
		//update con cache?
		if(!empty($conditions)) {
			$conCacheKey = $this->formatKey($class, $conditions);
			$this->modelCache[$conCacheKey] = $model;
		}
		//sync relations
		foreach($meta['relations'] as $k => $v) {
			$model->$k = $this->syncRelation($model, $k, $v);
		}
		//return
		return $model;
	}

	public function save($model) {
		//set vars
		$data = [];
		$result = null;
		$class = get_class($model);
		$table = $this->dbTable($class);
		$meta = $this->classMeta($class);
		//valid model?
		if(!property_exists($model, $meta['id'])) {
			throw new \Exception("Model cannot be saved without an ID field: " . $meta['id']);
		}
		//cache keys
		$changeCacheKey = spl_object_hash($model);
		$modelCacheKey = $this->formatKey($class, [ $meta['id'] => $model->{$meta['id']} ]);
		//get public data
		if($model instanceOf Model && $model->{$meta['id']}) {
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
			if($k === $meta['id'] || in_array($k, $meta['ignore']) || is_object($v)) {
				unset($data[$k]);
				continue;
			}
			//null field?
			if(empty($v) && isset($meta['props'][$k]) && $meta['props'][$k]['null']) {
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
			//stop here?
			if($data === false) {
				return false;
			}
			//save data
			if($model->{$meta['id']}) {
				//update query
				$result = $this->kernel->db->update($table, $data, [ $meta['id'] => $model->{$meta['id']} ]);
			} else {
				//insert query
				$result = $this->kernel->db->insert($table, $data);
				//cache insert ID?
				if($result !== false) {
					$model->{$meta['id']} = $this->kernel->db->insert_id;
				}
			}
			//update caches?
			if($result !== false) {
				//update load cache
				$this->modelCache[$modelCacheKey] = $model;
				//reset change cache
				$this->changeCache[$changeCacheKey] = [];
				//sync relations
				foreach($meta['relations'] as $k => $v) {
					$model->$k = $this->syncRelation($model, $k, $v);
				}
			}
		}
		//return
		return ($result !== false && $model->{$meta['id']}) ? $model->{$meta['id']} : false;
	}

	public function delete($model) {
		//set vars
		$result = true;
		$class = get_class($model);
		$table = $this->dbTable($class);
		$meta = $this->classMeta($class);
		//valid model?
		if(!property_exists($model, $meta['id'])) {
			throw new \Exception("Model cannot be deleted without an ID field: " . $meta['id']);
		}
		//anything to delete?
		if($id = $model->{$meta['id']}) {
			//delete event
			if($this->kernel->event('orm.delete', $model) === false) {
				return false;
			}
			//delete query
			$result = $this->kernel->db->delete($table, [ $meta['id'] => $id ]);
			//cache keys
			$changeCacheKey = spl_object_hash($model);
			$modelCacheKey = $this->formatKey($class, [ $meta['id'] => $id ]);
			//delete change cache?
			if(isset($this->changeCache[$changeCacheKey])) {
				unset($this->changeCache[$changeCacheKey]);
			}
			//delete model cache?
			if(isset($this->modelCache[$modelCacheKey])) {
				unset($this->modelCache[$modelCacheKey]);
			}
		}
		//return
		return $result;
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
			$class = $this->formatClass($name);
			$meta = $this->classMeta($class);
			$table = $meta['table'] ?: $this->tableName;
			$namespace = strtolower(explode('\\', $class)[0]);
			$name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
		}
		//update placeholders
		$table = str_replace([ '{namespace}', '{name}' ], [ $namespace, $name ], $table);
		//return
		return $this->kernel->event('orm.table', $table, $name);
	}

	protected function doQuery($name, array $conditions=[], $collection=false) {
		//set vars
		$result = [];
		$whereSql = [];
		$table = $this->dbTable($name);
		//check if all conditions empty?
		if(!array_filter($conditions, function($item) { return !empty($item); })) {
			$conditions = [];
		}
		//run query?
		if($conditions) {
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
				$isCollection = stripos($meta['type'], 'many') > 0;
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

	protected function classMeta($class) {
		//has class meta?
		if(method_exists($class, '__meta')) {
			return $class::__meta();
		}
		//default
		return [
			'id' => 'id',
			'table' => '',
			'ignore' => [],
			'props' => [],
			'relations' => [],
			'errors' => [],
			'errorsArray' => false,
			'readonly' => false,
			'hydrating' => false,
			'saving' => false,
		];
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