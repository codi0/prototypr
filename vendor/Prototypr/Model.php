<?php

namespace Prototypr;

class Model {

	private $__meta = [];
	private static $__metaTpl = [];

	protected $kernel;

	public final function __construct(array $opts=[], $merge=true) {
		//setup meta
		$this->__meta = self::__meta();
		//loop through props
		foreach($this as $k => $v) {
			//delete public property?
			if(isset($this->__meta['props'][$k])) {
				unset($this->$k);
			}
			//update value?
			if(array_key_exists($k, $opts)) {
				$this->$k = $opts[$k];
			}
		}
		//set kernel?
		if(!$this->kernel) {
			$this->kernel = prototypr();
		}
		//mark as constructed
		$this->__meta['constructed'] = true;
		//construct hook
		$this->onConstruct($opts);
		//hydrate hook?
		if($this->isHydrated()) {
			//mark as hydrated
			$this->__meta['hydrated'] = true;
			//call hook
			$this->onHydrate();
		}
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
		if($this->__meta['constructed'] && $this->__meta['readonly']) {
			throw new \Exception("Model is read only");
		}
		//property exists?
		if(!isset($this->__meta['props'][$key])) {
			throw new \Exception("Property $key not found");
		}
		//custom filters
		foreach($this->__meta['props'][$key]['filters'] as $index => $cb) {
			//lazy load callback?
			if(is_array($cb) && is_string($cb[0]) && $cb[0][0] === '$') {
				$cb[0] = eval("return $cb[0];");
				$this->__meta['props'][$key]['filters'] = $cb;
			}
			//execute callback
			$val = call_user_func($cb, $val);
		}
		//filter value
		$val = $this->onFilterVal($key, $val);
		//update value?
		if($this->__meta['props'][$key]['value'] !== $val) {
			//set value
			$this->__meta['props'][$key]['value'] = $val;
			//notify change?
			if($this->__meta['hydrated']) {
				$this->kernel->orm->onChange($this, $key, $val);
			}
		}
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
		$this->__meta['readonly'] = (bool) $readOnly;
	}

	public final function isHydrated() {
		//get id field
		$idField = $this->__meta['id'];
		//return
		return isset($this->$idField) && $this->$idField;
	}

	public final function isValid() {
		//reset errors
		$this->__meta['errors'] = [];
		//loop through props
		foreach($this->__meta['props'] as $key => $meta) {
			//get value
			$val = $this->__meta['props'][$key]['value'];
			//process custom rules
			foreach($this->__meta['props'][$key]['rules'] as $index => $cb) {
				//lazy load callback?
				if(is_array($cb) && is_string($cb[0]) && $cb[0][0] === '$') {
					$cb[0] = eval("return $cb[0];");
					$this->__meta['props'][$key]['rules'] = $cb;
				}
				//call rule
				$error = '';
				$res = call_user_func_array($cb, [ $val, &$error ]);
				//has error?
				if($error || ($res && is_string($res)) || $res === false) {
					//format error message
					$error = $error ?: (is_string($res) && $res ? $res : 'Invalid data');
					//add error
					$this->addError($key, $error);
				}
			}
		}
		//validate hook
		$this->onValidate();
		//return
		return empty($this->__meta['errors']);
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

	public final function get(array $where=[]) {
		//is hydrated?
		if(!$this->isHydrated()) {
			//data found?
			if(!$data = $this->kernel->orm->query($this, $where)) {
				return false;
			}
			//loop through data
			foreach($data as $k => $v) {
				//set property?
				if(isset($this->__meta['props'][$k])) {
					$this->$k = $v;
				}
			}
			//mark as hydrated
			$this->__meta['hydrated'] = true;
			//hydrate hook
			$this->onHydrate();
		}
		//success
		return true;
	}

	public final function set(array $data) {
		//loop through data
		foreach($data as $k => $v) {
			//set property?
			if(isset($this->__meta['props'][$k])) {
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
		if($id = $this->kernel->orm->save($this)) {
			//save hook
			$this->onSave();
		} else {
			//add error
			$this->addError('unknown', 'Unable to save record. Please try again.');
		}
		//return
		return ($id && !$this->__meta['errors']) ? $id : false;
	}

	protected final function addError($key, $val) {
		//multiple errors per kay?
		if($this->__meta['errorsArray']) {
			//create array?
			if(!isset($this->__meta['errors'][$key])) {
				$this->__meta['errors'][$key] = [];
			}
			//add to array
			$this->__meta['errors'][$key][] = $val;
		} else {
			//update value
			$this->__meta['errors'][$key] = $val;
		}
	}

	protected final function mergeErrors(array $errors) {
		//get all errors
		$errors = func_get_args();
		//merge errors
		$this->__meta['errors'] = array_merge($this->__meta['errors'], ...$errors);

	}

	/* HOOK METHODS */

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
		$orgVal = $this->__meta['props'][$key]['value'];
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
		//generate meta?
		if(!static::$__metaTpl) {
			//set vars
			$meta = [
				'id' => 'id',
				'table' => '',
				'ignore' => [],
				'props' => [],
				'errors' => [],
				'errorsArray' => false,
				'readonly' => false,
				'constructed' => false,
				'hydrated' => false,
			];
			//do reflection
			$refClass = new \ReflectionClass(static::class);
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
					//set property meta?
					if(!empty($args) && isset($meta['props'][$name][$param])) {
						$meta['props'][$name][$param] = $args;
					}
				}
			}
			//set property
			static::$__metaTpl = $meta;
		}
		//return key?
		if($key && isset(static::$__metaTpl[$key])) {
			return static::$__metaTpl[$key];
		}
		//return
		return $key ? null : static::$__metaTpl;
	}

	private final static function parseDocBlock($reflection) {
		//set vars
		$res = [];
		$docBlock = $reflection->getDocComment();
		//parse comment
		if($docBlock && preg_match_all('/([a-z0-9]+)\(([^\)]+)\)/i', $docBlock, $matches)) {
			//loop through matches
			foreach($matches[1] as $key => $val) {
				//set vars
				$args = [];
				$param = lcfirst(trim($val));
				$parts = array_map('trim', explode(',', trim($matches[2][$key])));
				//process args
				foreach($parts as $k => $v) {
					//method call?
					if($v && preg_match('/(::|->)/', $v, $match)) {
						//parse string
						$exp = explode($match[1], $v);
						$b = array_pop($exp);
						$a = implode($match[1], $exp);
						//set callback
						$v = [ $a, $b ];
					}
					//add arg?
					if(!empty($v)) {
						$args[] = $v;
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