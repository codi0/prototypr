<?php

namespace Prototypr;

class Model {

	private $__meta = [];
	private static $__metaTpl = [];

	protected $kernel;

	public final function __construct(array $opts=[], $merge=true) {
		//set meta
		$this->__meta = self::__meta();
		//set kernel
		$this->kernel = (isset($opts['kernel']) && $opts['kernel']) ? $opts['kernel'] : prototypr();
		//start hydrating
		$this->__meta['hydrating'] = true;
		//loop through props
		foreach($this as $k => $v) {
			//delete property?
			if(isset($this->__meta['props'][$k])) {
				unset($this->$k);
			}
			//set property?
			if(array_key_exists($k, $opts)) {
				$this->$k = $opts[$k];
			}
		}
		//finish hydrating
		$this->__meta['hydrating'] = false;
		//construct hook
		$this->onConstruct($opts);
	}

	public final function __isset($key) {
		return isset($this->__meta['props'][$key]);
	}

	public final function __get($key) {
		//property exists?
		if(!isset($this->__meta['props'][$key])) {
			throw new \Exception("Property $key not found");
		}
		//return
		return $this->__meta['props'][$key]['value'];
	}

	public final function __set($key, $val) {
		//read only?
		if($this->__meta['readonly'] && !$this->__meta['hydrating']) {
			throw new \Exception("Model is read only");
		}
		//property exists?
		if(!isset($this->__meta['props'][$key])) {
			throw new \Exception("Property $key not found");
		}
		//run custom filters
		foreach($this->__meta['props'][$key]['filters'] as $filter) {
			$val = $this->kernel->validator->filter($filter, $val);
		}
		//filter value
		$val = $this->onFilterVal($key, $val);
		//update value?
		if($this->__meta['props'][$key]['value'] !== $val) {
			//set value
			$this->__meta['props'][$key]['value'] = $val;
			//notify of change?
			if(!$this->__meta['hydrating']) {
				$this->kernel->orm->onChange($this, $key, $val);
			}
		}
	}

	public final function id() {
		//get id field
		$idField = $this->__meta['id'];
		//return
		return $this->$idField;
	}

	public final function toArray() {
		//set vars
		$arr = [];
		//loop through data
		foreach($this->__meta['props'] as $key => $meta) {
			$arr[$key] = $meta['value'];
		}
		//return
		return $arr;
	}

	public final function readOnly($readOnly=true) {
		//mark as read only
		$this->__meta['readonly'] = (bool) $readOnly;
	}

	public final function errors($reset=false) {
		//cache errors
		$errors = $this->__meta['errors'];
		//reset now?
		if($reset && $errors) {
			$this->__meta['errors'] = [];
		}
		//return
		return $errors;
	}

	public final function isValid() {
		//reset errors
		$this->__meta['errors'] = [];
		//loop through props
		foreach($this->__meta['props'] as $key => $meta) {
			//get value
			$val = $this->__meta['props'][$key]['value'];
			//process custom rules
			foreach($this->__meta['props'][$key]['rules'] as $rule) {
				//set vars
				$error = '';
				//call validator
				if(!$this->kernel->validator->isValid($rule, $val, $error)) {
					$this->addError($key, $error);
				}
			}
		}
		//validate hook
		$this->onValidate();
		//check relations
		foreach($this->__meta['relations'] as $prop => $meta) {
			//is relation valid?
			if($meta['onValidate'] && !$this->$prop->isValid()) {
				//set errors
				foreach($this->$prop->errors() as $k => $v) {
					$this->addError($k, $v);
				}
			}
		}
		//return
		return empty($this->__meta['errors']);
	}

	public final function get(array $where=[]) {
		//is hydrated?
		if(!$this->id()) {
			//start hydrating
			$this->__meta['hydrating'] = true;
			//hydrate data
			$this->kernel->orm->hydrate($this, $where);
			//finish hydrating
			$this->__meta['hydrating'] = false;
		}
		//return
		return $this->id();
	}

	public final function set(array $data) {
		//setter hook
		$data = $this->onSet($data);
		//loop through data
		foreach($data as $k => $v) {
			//set property?
			if(isset($this->__meta['props'][$k])) {
				$this->$k = $v;
			}
		}
		//check relations
		foreach($this->__meta['relations'] as $prop => $meta) {
			//set data?
			if($meta['onSet']) {
				$this->$prop->set($data);
			}
		}
		//chain it
		return $this;
	}

	public final function save() {
		//is valid?
		if(!$this->isValid()) {
			return false;
		}
		//read only?
		if($this->__meta['readonly']) {
			return $this->id ?: false;
		}
		//data saved?
		if(!$id = $this->kernel->orm->save($this)) {
			$this->addError('unknown', 'Unable to save record. Please try again.');
			return false;
		}
		//currently saving?
		if(!$this->__meta['saving']) {
			//start saving
			$this->__meta['saving'] = true;
			//check relations
			foreach($this->__meta['relations'] as $prop => $meta) {
				//save relation now?
				if($meta['onSave'] && !$this->$prop->save()) {
					//set errors
					foreach($this->$prop->errors() as $k => $v) {
						$this->addError($k, $v);
					}
				}
			}
			//save hook
			$this->onSave();
			//finish saving
			$this->__meta['saving'] = false;
		}
		//return
		return empty($this->__meta['errors']) ? $id : false;
	}

	protected final function addError($key, $val) {
		//multiple errors per kay?
		if($this->__meta['errorsArray']) {
			//create array?
			if(!isset($this->__meta['errors'][$key])) {
				$this->__meta['errors'][$key] = [];
			}
			//add to array
			foreach((array) $val as $v) {
				//is dupe?
				if($v && !in_array($v, $this->__meta['errors'][$key])) {
					$this->__meta['errors'][$key][] = $v;
				}
			}
		} else {
			//update value?
			if(!empty($val)) {
				$this->__meta['errors'][$key] = $val;
			}
		}
	}

	/* HOOK METHODS */

	protected function onConstruct(array $opts) {
		return;
	}

	protected function onSet(array $data) {
		return $data;
	}

	protected function onFilterVal($key, $val) {
		//get org value
		$orgVal = $this->__meta['props'][$key]['value'];
		//scalar mis-match?
		if(is_scalar($orgVal) && !is_scalar($val)) {
			return $orgVal;
		}
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

	/* Static helpers */

	public final static function __meta($key=null) {
		//get class
		$class = static::class;
		//generate meta?
		if(!isset(self::$__metaTpl[$class])) {
			//meta data
			$meta = [
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
			//default rel
			$defRel = [
				'model' => null,
				'where' => [],
				'type' => 'one2one',
				'lazy' => true,
				'onSet' => true,
				'onValidate' => true,
				'onSave' => true,
			];
			//do reflection
			$refClass = new \ReflectionClass($class);
			//parse docblock
			$docblock = static::parseDocBlock($refClass);
			//loop through results
			foreach($docblock as $param => $args) {
				//set property meta?
				if(isset($meta[$param])) {
					//cast type?
					if(is_string($meta[$param])) {
						$args = implode(',', $args);
					} else if(is_bool($meta[$param])) {
						$args = (!$args || $args[0] !== 'false');
					}
					//set param?
					if(!empty($args)) {
						$meta[$param] = $args;
					}
				}
			}
			//process public properties
			foreach($refClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $refProp) {
				//skip property?
				if($refProp->isStatic()) {
					continue;
				}
				//get prop name
				$name = $refProp->getName();
				//add to meta data
				$meta['props'][$name] = [
					'value' => $refClass->getDefaultProperties()[$name],
					'filters' => [],
					'rules' => [],
				];
				//parse docblock
				$docblock = self::parseDocBlock($refProp);
				//loop through results
				foreach($docblock as $param => $args) {
					//is relation?
					if($param === 'relation') {
						$defRel['model'] = $param;
						$meta['relations'][$name] = array_merge($defRel, $args);
						unset($meta['props'][$name]);
						break;
					}
					//set property meta?
					if(isset($meta['props'][$name][$param])) {
						$meta['props'][$name][$param] = $args;
					}
				}
			}
			//set template
			self::$__metaTpl[$class] = $meta;
		}
		//return key?
		if($key && isset(self::$__metaTpl[$class][$key])) {
			return self::$__metaTpl[$class][$key];
		}
		//return
		return $key ? null : self::$__metaTpl[$class];
	}

	private final static function parseDocBlock($reflection) {
		//set vars
		$res = [];
		$docBlock = $reflection->getDocComment();
		//build regex
		$open = '[';
		$close = ']';
		$regex = str_replace([ ':open', ':close' ], [ $open, $close ], "/([a-z0-9]+)\:open([^\:close]+)\:close/i");
		//parse comment
		if($docBlock && preg_match_all($regex, $docBlock, $matches)) {
			//loop through matches
			foreach($matches[1] as $key => $val) {
				//set vars
				$args = [];
				$param = lcfirst(trim($val));
				$data = trim($matches[2][$key]);
				//is json?
				if($data && $data[0] === '{') {
					//decode json
					$args = json_decode($data, true);
					//is valid?
					if(empty($args)) {
						throw new \Exception("Invalid json for model meta parameter $param");
					}
				} else {
					//split data
					$parts = preg_split('/\s+/', $data);
					$parts = array_map('trim', $parts);
					//process args
					foreach($parts as $k => $v) {
						//remove delims
						$v = trim(trim($v, "|,"));
						//add arg?
						if(!empty($v)) {
							$args[] = $v;
						}
					}
				}
				//add to result
				$res[$param] = $args;
			}
		}
		//return
		return $res;
	}

}