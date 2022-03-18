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
		//set kernel
		$this->kernel = isset($opts['kernel']) ? $opts['kernel'] : prototypr();
		//process props?
		if(!$this->__meta['data']) {
			$this->__meta['data'] = $this->processProps();
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
		return isset($this->__meta['data'][$key]['value']);
	}

	public function __get($key) {
		//property exists?
		if(!array_key_exists($key, $this->__meta['data'])) {
			throw new \Exception("Property $key not found");
		}
		//return
		return $this->__meta['data'][$key]['value'];
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
		//custom filters
		foreach($this->__meta['data'][$key]['filters'] as $index => $cb) {
			//lazy load callback?
			if(is_array($cb) && is_string($cb[0]) && $cb[0][0] === '$') {
				$cb[0] = eval("return $cb[0];");
				$this->__meta['data'][$key]['filters'] = $cb;
			}
			//execute callback
			$val = call_user_func($cb, $val);
		}
		//filter value
		$val = $this->onFilterVal($key, $val);
		//update value?
		if($this->__meta['data'][$key]['value'] !== $val) {
			//set value
			$this->__meta['data'][$key]['value'] = $val;
			//notify change?
			if($this->__meta['hydrated']) {
				$this->kernel->orm->onChange($this, $key, $val);
			}
		}
	}

	public function toArray() {
		//set vars
		$arr = [];
		//loop through data
		foreach($this->__meta['data'] as $key => $meta) {
			$arr[$key] = $meta['value'];
		}
		//return
		return $arr;
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
		return  $this->kernel->orm->isCached($this);
	}

	public function isValid() {
		//reset errors
		$this->errors = [];
		//loop through props
		foreach($this->__meta['data'] as $key => $meta) {
			//get value
			$val = $this->__meta['data'][$key]['value'];
			//process custom rules
			foreach($this->__meta['data'][$key]['rules'] as $index => $cb) {
				//lazy load callback?
				if(is_array($cb) && is_string($cb[0]) && $cb[0][0] === '$') {
					$cb[0] = eval("return $cb[0];");
					$this->__meta['data'][$key]['rules'] = $cb;
				}
				//call rule
				$error = '';
				$res = call_user_func_array($cb, [ $val, &$error ]);
				//has error?
				if($error || ($res && is_string($res)) || $res === false) {
					$this->errors[$key] = $error ?: (is_string($res) && $res ? $res : 'Invalid data');
					break;
				}
			}
		}
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
		//get org value
		$orgVal = $this->__meta['data'][$key]['value'];
		//cast by type
		if(is_string($orgVal)) {
			$val = trim($val);
		} else if(is_int($orgVal)) {
			$val = intval($val) ?: NULL;
		} else if(is_numeric($orgVal)) {
			$val = floatval($val) ?: NULL;
		} else if(is_array($orgVal)) {
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

	protected function processProps() {
		//set vars
		$data = [];
		$ref = new \ReflectionObject($this);
		//process public properties
		foreach($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
			//skip property?
			if($prop->isStatic()) {
				continue;
			}
			//get prop name
			$name = $prop->getName();
			//add to array
			$data[$name] = [
				'value' => $this->$name,
				'filters' => [],
				'rules' => [],
			];
			//remove original
			unset($this->$name);
			//has comment?
			if($comment = $prop->getDocComment()) {
				//parse comment
				if(preg_match_all('/(\w+)\((.*)\)/',$comment, $matches)) {
					//loop through matches
					foreach($matches[1] as $key => $val) {
						//get param
						$param = trim(strtolower($val));
						$args = array_map('trim', explode(',', trim($matches[2][$key])));
						//param exists
						if($args && isset($data[$name][$param])) {
							//process args
							foreach($args as $k => $v) {
								//method call?
								if(preg_match('/(::|->)/', $v, $match)) {
									//parse string
									$exp = explode($match[1], $v);
									$b = array_pop($exp);
									$a = implode($match[1], $exp);
									//set callback
									$args[$k] = [ $a, $b ];
								}
							}
							//add to array
							$data[$name][$param] = $args;
						}
					}
				}
			}
		}
		//return
		return $data;
	}

}