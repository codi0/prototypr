<?php

namespace Proto2\Orm;

class Orm {

	protected $modelCache = [];
	protected $changeCache = [];

	protected $modelClass = '{namespace}\Model\{name}';
	protected $tableName = '{namespace}_{name}';

	protected $namespace = 'App';

	protected $db;
	protected $validator;
	protected $eventManager;

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
		//pass objects
		$opts['__db'] = $this->db;
		$opts['__validator'] = $this->validator;
		//create model
		$model = new $class($opts);
		//sync relations
		foreach($meta['relations'] as $k => $v) {
			$model->$k = $this->syncRelation($model, $k, $v);
		}
		//update model cache?
		if($idVal = $model->{$meta['id']}) {
			$idCacheKey = $this->formatKey($class, [ $meta['id'] => $idVal ]);
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
		//update isNew meta?
		if($idVal = $model->{$meta['id']}) {
			$this->classMeta($class, [ 'object' => $model, 'key' => 'isNew', 'val' => false ]);
		}
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
		$meta = $this->classMeta($class, [ 'object' => $model ]);
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
		//has ID?
		if($idVal = $model->{$meta['id']}) {
			//update model cache
			$idCacheKey = $this->formatKey($class, [ $meta['id'] => $idVal ]);
			$this->modelCache[$idCacheKey] = $model;
			//update isNew meta
			$this->classMeta($class, [ 'object' => $model, 'key' => 'isNew', 'val' => false ]);
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
		$dataOld = [];
		$result = null;
		$allEmpty = true;
		$class = get_class($model);
		$table = $this->dbTable($class);
		$meta = $this->classMeta($class, [ 'object' => $model ]);
		//valid model?
		if(!property_exists($model, $meta['id'])) {
			throw new \Exception("Model cannot be saved without an ID field: " . $meta['id']);
		}
		//cache keys
		$idVal = $model->{$meta['id']};
		$changeCacheKey = spl_object_hash($model);
		$modelCacheKey = $this->formatKey($class, [ $meta['id'] => $idVal ]);
		//get public data
		if($model instanceOf Model && $idVal) {
			//use change cache?
			if(isset($this->changeCache[$changeCacheKey])) {
				//loop through changes
				foreach($this->changeCache[$changeCacheKey] as $k => $v) {
					$data[$k] = $v['to'];
					$dataOld[$k] = $v['from'];
				}
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
		//ignore ID?
		if($meta['id'] && !$idVal) {
			$meta['ignore'][] = $meta['id'];
		}
		//filter data
		foreach($data as $k => $v) {
			//remove key?
			if(in_array($k, $meta['ignore']) || is_object($v)) {
				//remove key
				unset($data[$k]);
				//remove old key?
				if(array_key_exists($k, $dataOld)) {
					unset($dataOld[$k]);
				}
				//next
				continue;
			}
			//update key?
			if(empty($v) && isset($meta['props'][$k]) && $meta['props'][$k]['null']) {
				$data[$k] = NULL;
			} else if(is_bool($v)) {
				$data[$k] = $v ? 1 : 0;
			} else if(is_array($v)) {
				$data[$k] = json_encode($v);
			}
			//update old key?
			if(array_key_exists($k, $dataOld)) {
				if(empty($dataOld[$k]) && isset($meta['props'][$k]) && $meta['props'][$k]['null']) {
					$dataOld[$k] = NULL;
				} else if(is_bool($dataOld[$k])) {
					$dataOld[$k] = $dataOld[$k] ? 1 : 0;
				} else if(is_array($dataOld[$k])) {
					$dataOld[$k] = json_encode($dataOld[$k]);
				}
				//does old equal new?
				if($dataOld[$k] === $data[$k]) {
					unset($data[$k], $dataOld[$k]);
					continue;
				}
			}
			//is non-empty value?
			if(!empty($data[$k])) {
				$allEmpty = false;
			}
		}
		//wipe data?
		if($allEmpty) {
			$data = [];
		}
		//call save event?
		if(!empty($data)) {
			$data = $this->eventManager->dispatch('orm.save', [
				'model' => $model,
				'data' => $data,
				'dataOld' => $dataOld,
			])->data;
		}
		//anything to save?
		if(!empty($data)) {
			//insert or update?
			if($meta['isNew'] === true || ($meta['isNew'] === null && !$idVal)) {
				//insert query
				$result = $this->db->insert($table, $data);
				//cache insert ID?
				if($result !== false) {
					//update ID
					$model->{$meta['id']} = $idVal = $this->db->insert_id;
					//update isNew meta
					$this->classMeta($class, [
						'object' => $model,
						'key' => 'isNew',
						'val' => false,
					]);
				}
			} else {
				//update query
				$result = $this->db->update($table, $data, [ $meta['id'] => $idVal ]);
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
		return ($result !== false && $idVal) ? $idVal : false;
	}

	public function delete($model) {
		//set vars
		$result = true;
		$class = get_class($model);
		$table = $this->dbTable($class);
		$meta = $this->classMeta($class, [ 'object' => $model ]);
		//valid model?
		if(!property_exists($model, $meta['id'])) {
			throw new \Exception("Model cannot be deleted without an ID field: " . $meta['id']);
		}
		//anything to delete?
		if($idVal = $model->{$meta['id']}) {
			//delete event
			if($this->eventManager->dispatch('orm.delete', [ 'model' => $model ]) === false) {
				return false;
			}
			//delete query
			$result = $this->db->delete($table, [ $meta['id'] => $idVal ]);
			//unset value
			$model->{$meta['id']} = null;
			//reset isNew meta
			$this->classMeta($class, [ 'object' => $model, 'key' => 'isNew', 'val' => true ]);
			//cache keys
			$changeCacheKey = spl_object_hash($model);
			$modelCacheKey = $this->formatKey($class, [ $meta['id'] => $idVal ]);
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
		return ($result !== false);
	}

	public function onChange($model, $key, $fromVal, $toVal) {
		//get model hash
		$hash = spl_object_hash($model);
		//create hash array?
		if(!isset($this->changeCache[$hash])) {
			$this->changeCache[$hash] = [];
		}
		//create hash.key array?
		if(!isset($this->changeCache[$hash][$key])) {
			//set from and to value
			$this->changeCache[$hash][$key] = [
				'from' => $fromVal,
				'to' => $toVal,
			];
		}
		//update to value
		$this->changeCache[$hash][$key]['to'] = $toVal;
	}

	public function clearCache() {
		//clear vars
		$this->modelCache = [];
		$this->changeCache = [];
		//return
		return true;
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
		return $this->eventManager->dispatch('orm.table', [
			'table' => $table,
			'name' => $name,
		])->table;
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
			$query = $this->db->prepare("SELECT * FROM $table WHERE $whereSql", $conditions);
			$result = (array) $this->db->$method($query) ?: [];
			//decode json
			$result = $this->decodeJson($result);
		}
		//return
		return $result;
	}

	protected function syncRelation($model, $prop, array $meta) {
		//check conditionals
		foreach($meta['if'] as $k => $v) {
			//skip relation?
			if(!property_exists($model, $k) || $model->$k != $v) {
				return null;
			}
		}
		//resolve where conditions
		foreach($meta['where'] as $k => $v) {
			//is placeholder?
			if($v && $v[0] === ':') {
				//get field
				$field = substr($v, 1);
				//update condition
				$meta['where'][$k] = $model->$field;
			}
		}
		//does relation exist?
		if($relation = $model->$prop) {
			//is collection?
			if($relation instanceOf ModelCollection) {
				$relation->inject($meta['where']);
			}
			//is model?
			if($relation instanceOf Model) {
				//loop through conditions
				foreach($meta['where'] as $k => $v) {
					$relation->$k = $v;
				}
			}
		} else {
			//model or collection?
			if(stripos($meta['type'], 'many') > 0) {
				//load collection
				$relation = $this->loadCollection($meta['model'], $meta['where']);
			} else {
				//set vars
				$orm = $this;
				//create proxy relation
				$relation = new Proxy(function() use($orm, $model, $prop, $meta) {
					//load model
					$rel = $orm->load($meta['model'], $meta['where']);
					//set prop
					$model->$prop = $rel;
					//return
					return $rel;
				});
			}
		}
		//return
		return $relation;
	}

	protected function classMeta($class, array $opts=[]) {
		//has class meta?
		if(method_exists($class, '__meta')) {
			return $class::__meta($opts);
		}
		//default
		return [
			'id' => 'id',
			'table' => '',
			'ignore' => [],
			'props' => [],
			'relations' => [],
			'errors' => [],
			'errorsArray' => null,
			'readonly' => null,
			'hydrating' => null,
			'processing' => null,
			'isNew' => null,
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
		return $this->eventManager->dispatch('orm.class', [
			'class' => $class,
			'name' => $name,
		])->class;
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
		//loop through array
		foreach($arr as $key => $val) {
			//is array?
			if(is_array($val) || is_object($val)) {
				$arr[$key] = $this->decodeJson((array) $val);
			} else if(!empty($val)) {
				$arr[$key] = json_decode($val, true) ?: $val;
			}
		}
		//return
		return $arr;
	}

}