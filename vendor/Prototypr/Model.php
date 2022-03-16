<?php

namespace Prototypr;

class Model {

	private $__meta = [
		'data' => [],
		'readonly' => false,
		'hydrated' => false,
		'constructed' => false,
		'protected' => [ 'kernel', 'errors' ],
	];

	protected $kernel;
	protected $errors = [];

	public static $idField = 'id';
	public static $dbTable = '';
	public static $dbIgnore = [];

	public function __construct(array $opts=[], $merge=true) {
		//get public props?
		if(!$this->__meta['data']) {
			//use reflection
			$ref = new \ReflectionObject($this);
			//process public properties
			foreach($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
				//non-static property?
				if(!$prop->isStatic()) {
					$key = $prop->getName();
					$this->__meta['data'][$key] = $this->$key;
					unset($this->$key);
				}
			}
		}
		//set opts
		foreach($opts as $k => $v) {
			//property exists?
			if(array_key_exists($k, $this->__meta['data']) || property_exists($this, $k)) {
				//is array?
				if($merge && $this->$k === (array) $this->$k) {
					$this->$k = array_merge($this->$k, $v);
				} else {
					$this->$k = $v;
				}
			}
		}
		//construct hook
		$this->onConstruct($opts);
		//is hydrated?
		if($this->isHydrated()) {
			//update flag
			$this->__meta['hydrated'] = true;
			//hydrate hook
			$this->onHydrate();
		}
		//update flag
		$this->__meta['constructed'] = true;
	}

	public function __isset($key) {
		return isset($this->__meta['data'][$key]);
	}

	public function __get($key) {
		//property exists?
		if(!array_key_exists($key, $this->__meta['data'])) {
			throw new \Exception("Property $key not found");
		}
		//return
		return $this->__meta['data'][$key];
	}

	public function __set($key, $val) {
		//read only?
		if($this->__meta['readonly']) {
			throw new \Exception("Model is read only");
		}
		//property exists?
		if(!array_key_exists($key, $this->__meta['data'])) {
			throw new \Exception("Property $key not found");
		}
		//filter value
		$val = $this->onFilterVal($key, $val);
		//update value?
		if($this->__meta['data'][$key] !== $val) {
			//set value
			$this->__meta['data'][$key] = $val;
			//notify change?
			if($this->__meta['hydrated']) {
				$this->kernel->orm->onChange($this, $key, $val);
			}
		}
	}

	public function toArray() {
		return $this->__meta['data'];
	}

	public function readOnly($readOnly=true) {
		$this->__meta['readonly'] = (bool) $readOnly;
	}

	public function isHydrated() {
		//is constructing?
		if(!$this->__meta['constructed']) {
			$idField = self::$idField;
			return isset($this->$idField) && $this->$idField;
		}
		//return
		return $this->kernel->orm->isCached($this);
	}

	public function isValid() {
		//reset errors
		$this->errors = [];
		//validate hook
		$this->onValidate();
		//return
		return empty($this->errors);
	}

	public function errors($reset=false) {
		//cache errors
		$errors = $this->errors;
		//reset now?
		if($reset && $errors) {
			$this->errors = [];
		}
		//return
		return $errors;
	}

	public function get(array $where=[]) {
		//is hydrated?
		if(!$this->isHydrated()) {
			//data found?
			if(!$data = $this->kernel->orm->query($this, $where)) {
				return false;
			}
			//loop through data
			foreach($data as $k => $v) {
				//protected property?
				if(in_array($k, $this->__meta['protected'])) {
					throw new \Exception("Property $k is protected");
				}
				//set property?
				if(array_key_exists($k, $this->__meta['data'])) {
					$this->$k = $v;
				}
			}
			//update flag
			$this->__meta['hydrated'] = true;
			//hydrate hook
			$this->onHydrate();
		}
		//success
		return true;
	}

	public function set(array $data) {
		//loop through data
		foreach($data as $k => $v) {
			//protected property?
			if(in_array($k, $this->__meta['protected'])) {
				throw new \Exception("Property $k is protected");
			}
			//set property?
			if(array_key_exists($k, $this->__meta['data'])) {
				$this->$k = $v;
			}
		}
		//setter hook
		$this->onSet($data);
		//validate data
		$this->isValid();
		//chain it
		return $this;
	}

	public function save() {
		//is valid?
		if(!$this->isValid()) {
			return false;
		}
		//read only?
		if($this->__meta['readonly']) {
			return $this->id ?: false;
		}
		//data saved?
		if($id = $this->kernel->orm->save($this)) {
			//save hook
			$this->onSave();
		} else {
			//save error
			$this->errors['unknown'] = 'Unable to save record. Please try again.';
		}
		//return
		return ($id && !$this->errors) ? $id : false;
	}

	protected function addError($key, $val) {
		$this->errors[$key] = $val;
	}

	protected function onConstruct(array $opts) {
		return;
	}

	protected function onHydrate() {
		return;
	}

	protected function onSet(array $data) {
		return;
	}

	protected function onFilterVal($key, $val) {
		//check type
		if(is_string($this->$key)) {
			$val = trim($val);
		} else if(is_int($this->$key)) {
			$val = intval($val) ?: NULL;
		} else if(is_array($this->$key)) {
			$val = (array) ($val ?: []);
		} else {
			$val = $val ?: NULL;
		}
		//return
		return $val;	
	}

	protected function onValidate() {
		return;
	}

	protected function onSave() {
		return;
	}

}