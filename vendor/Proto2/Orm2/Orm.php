<?php

namespace Proto2\Orm2;

class Orm {

	protected $idCache = [];
	protected $queryCache = [];
	protected $stateTracker = [];

	protected $mapping;
	protected $store;

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
		//create mapping?
		if(!$this->mapping) {
			$this->mapping = new Mapping;
		}
		//create store?
		if(!$this->store) {
			$this->store = new Store([ 'mapping' => $this->mapping ]);
		}
	}

	public function load($name, $query=[], $queryCache=true) {
		//get class
		$class = $this->getClassName($name);
		//format query
		$query = $this->formatQuery($query);
		//create object?
		if(!$entity = $this->checkCache($class, $query, $queryCache)) {
			//query data
			$data = $this->store->query($class, $query);
			//create entity
			$entity = $this->create($name, $data);
			//add to query cache?
			if(!empty($query)) {
				if($queryHash = $this->getCacheHash($class, $query)) {
					$this->queryCache[$queryHash] = $entity;
				}
			}
		}
		//return
		return $entity;
	}

	public function create($name, array $data=[]) {
		//get class
		$class = $this->getClassName($name);
		//set vars
		$args = $this->getConstructorArgs($class, $data);
		//create entity
		$entity = new $class(...$args);
		//attach entity
		$this->attach($entity);
		//return
		return $entity;
	}

	public function attach($entity) {
		//valid object?
		if(!$objHash = $this->getObjHash($entity)) {
			return false;
		}
		//get ID hash
		$id = $entity->id;
		$idHash = $id ? $this->getCacheHash($entity, $id) : null;
		//ID cache?
		if($idHash) {
			$this->idCache[$idHash] = $entity;
		}
		//state cache
		$this->stateTracker[$objHash] = $this->getState($entity);
		//return
		return true;
	}

	public function detach($entity) {
		//valid object?
		if(!$objHash = $this->getObjHash($entity)) {
			return false;
		}
		//ID cache
		$key = true;
		while($key) {
			if($key = array_search($entity, $this->idCache)) {
				unset($this->idCache[$key]);
			}
		}
		//query cache
		$key = true;
		while($key) {
			if($key = array_search($entity, $this->queryCache)) {
				unset($this->queryCache[$key]);
			}
		}
		//state tracker?
		if(isset($this->stateTracker[$objHash])) {
			unset($this->stateTracker[$objHash]);
		}
		//done
		return true;
	}

	public function isValid($entity, &$errors=[]) {
		//valid object?
		if(!$objHash = $this->getObjHash($entity)) {
			return false;
		}
		//return
		return true;
	}

	public function save($entity) {
		//valid object?
		if(!$objHash = $this->getObjHash($entity)) {
			return false;
		}
		//is state valid?
		if(!$this->isValid($entity)) {
			return false;
		}
		//set vars
		$changes = [];
		$currentState = $this->getState($entity);
		$prevState = isset($this->stateTracker[$objHash]) ? $this->stateTracker[$objHash] : [];
		//calculate changes
		foreach($currentState as $key => $val) {
			if(!array_key_exists($key, $prevState) || $prevState[$key] !== $val) {
				$changes[$key] = $val;
			}
		}
		//stop here?
		if(empty($changes)) {
			return true;
		}
		//save changes
		return $this->store->sync($entity, $changes);
	}

	public function delete($entity) {
		//valid object?
		if(!$objHash = $this->getObjHash($entity)) {
			return false;
		}
	}

	protected function checkCache($class, array $query, $queryCache=true) {
		//check for ID
		$id = isset($query['id']) ? $query['id'] : null;
		//get hashes
		$idHash = $id ? $this->getCacheHash($class, $id) : null;
		$queryHash = $query ? $this->getCacheHash($class, $query) : null;
		//ID match found?
		if($idHash && isset($this->idCache[$idHash])) {
			return $this->idCache[$idHash];
		}
		//query match found?
		if($queryCache && $queryHash && isset($this->queryCache[$queryHash])) {
			return $this->queryCache[$queryHash];
		}
		//not found
		return null;
	}

	protected function formatQuery($query) {
		//format query
		if(!is_array($query)) {
			//query is ID?
			if($query && is_scalar($query)) {
				$query = [ 'id' => $query ];
			} else {
				$query = [];
			}
		}
		//return
		return $query;
	}

	protected function getState($entity) {
		//set vars
		$state = [];
		$refObj = new \ReflectionObject($entity);
		//loop through properties
		foreach($refObj->getProperties() as $refProp) {
			//mark as accessible
			$refProp->setAccessible(true);
			//get name and value
			$name = $refProp->getName();
			$value = $refProp->getValue($entity);
			//add to array
			$state[$name] = $value;
		}
		//return
		return $state;
	}

	protected function getObjHash($entity) {
		//is object?
		if(!is_object($entity)) {
			return null;
		}
		//return
		return spl_object_hash($entity);
	}

	protected function getCacheHash($class, $data) {
		//is object?
		if(is_object($class)) {
			$class = get_class($class);
		}
		//return
		return md5($class . ($data ? json_encode($data) : ''));
	}

	protected function getClassName($name) {
		//name is class?
		if(class_exists($name)) {
			return $name;
		}
		//default
		return 'stdClass';
	}

	protected function getConstructorArgs($class, array $data) {
		//set vars
		$args = [];
		//check constructor?
		if($data && $class !== 'stdClass') {
			$refClass = new \ReflectionClass($class);
			$refParams = $refClass->getConstructor()->getParameters();
			//send as array?
			if($refParams && $refParams[0]->getType() == 'array') {
				$args[] = $data;
			} else {
				$args = array_values($data);
			}
		}
		//return
		return $args;
	}

}