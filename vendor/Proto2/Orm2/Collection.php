<?php

namespace Proto2\Orm2;

class Collection extends \ArrayObject {

	protected $orm;
	protected $name = '';
	protected $conditions = [];

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
		//name set?
		if(!$this->name) {
			throw new \Exception("Collection name required");
		}
		//orm set?
		if(!$this->orm) {
			throw new \Exception("ORM object not set");
		}
		//get data
		$data = isset($opts['models']) ? $opts['models'] : [];
		//call parent
		parent::__construct($data);
		//clear conditions?
		if(!empty($data)) {
			$this->conditions = [];
		}
	}

	public function name() {
		return $this->name;
	}

	public function toArray() {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//return
		return (array) $this;
	}

	public function errors($flatten=true) {
		//set vars
		$errors = [];
		//check for errors?
		if(!$this->conditions) {
			//loop through array
			foreach($this as $key => $model) {
				//loop through errors
				foreach($model->errors() as $k => $v) {
					//flatten errors?
					if($flatten) {
						//add error
						$errors[$k] = $v;
					} else {
						//create array?
						if(!isset($errors[$key])) {
							$errors[$key] = [];
						}
						//add error
						$errors[$key][$k] = $v;
					}
				}
			}
		}
		//return
		return $errors;
	}

	public function isValid() {
		//set vars
		$isValid = true;
		//check for errors?
		if(!$this->conditions) {
			//loop through array
			foreach($this as $model) {
				//is valid?
				if(!$model->isValid()) {
					$isValid = false;
				}
			}
		}
		//return
		return $isValid;
	}

	public function get($key) {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//does model exist?
		if(!isset($this[$key])) {
			throw new \Exception("Model not found: $key");
		}
		//return
		return $this[$key];
	}

	public function set(array $models, array $opts=[]) {
		//set opts
		$opts = array_merge([
			'overwrite' => true,
		], $opts);
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//delete existing?
		if($opts['overwrite']) {
			$this->delete();
		}
		//loop through array
		foreach($models as $model) {
			$this[] = $this->createModel($model);
		}
		//return
		return $this;
	}

	public function add($model) {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//add to array
		$this[] = $this->createModel($model);
		//return
		return true;
	}

	public function remove($model) {
		return $this->delete($model);
	}

	public function inject($prop, $val=null) {
		//convert to array?
		if(!is_array($prop)) {
			$prop = [ $prop => $val ];
		}
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//loop through models
		foreach($this as $model) {
			//loop through props
			foreach($prop as $k => $v) {
				$model->$k = $v;
			}
		}
		//return
		return true;
	}

	public function save() {
		//should save?
		if(!$this->conditions) {
			//loop through models
			foreach($this as $model) {
				$this->orm->save($model);
			}
		}
		//return
		return true;
	}

	public function delete($model=null) {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//loop through models
		foreach($this as $key => $val) {
			//match found?
			if(!$model || $model === $val) {
				unset($this[$key]);
				$val->delete();
			}
		}
		//return
		return true;
	}

	public function offsetIsset($key) {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//call parent
		return parent::offsetIsset($key);
	}

	#[\ReturnTypeWillChange]
	public function offsetGet($key) {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//call parent
		return parent::offsetGet($key);
	}

	#[\ReturnTypeWillChange]
	public function offsetSet($key, $val) {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//create model
		$val = $this->createModel($val);
		//call parent
		return parent::offsetSet($key, $val);
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset($key) {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//call parent
		return parent::offsetUnset($key);
	}

	#[\ReturnTypeWillChange]
	public function getIterator() {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//call parent
		return parent::getIterator();
	}

	#[\ReturnTypeWillChange]
	public function count() {
		//lazy load?
		if($this->conditions) {
			$this->lazyLoad();
		}
		//call parent
		return parent::count();
	}

	protected function lazyLoad() {
		//stop here?
		if(!$this->conditions) {
			return;
		}
		//get collection as array
		$models = $this->orm->loadCollection($this->name, $this->conditions, false);
		//clear opts
		$this->conditions = [];
		//loop through models
		foreach($models as $m) {
			$this[] = $this->createModel($m);
		}
	}

	protected function createModel($model) {
		//create model?
		if(is_array($model)) {
			$model = $this->orm->create($this->name, $model);
		}
		//valid model?
		if(!is_object($model)) {
			throw new \Exception("Unable to create model");
		}
		//return
		return $model;
	}

}