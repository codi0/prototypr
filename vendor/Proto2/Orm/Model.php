<?php

namespace Proto2\Orm;

class Model {

	private $__meta = [];
	private static $__metaTpl = [];

	private $__orm;
	private $__validator;

	public final function __construct(array $opts=[], $merge=true) {
		//set meta
		$this->__meta = self::__meta();
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
		$val = $this->__validator->filter($this->__meta['props'][$key]['filters'], $val);
		//type cast value
		$val = $this->onTypecast($key, $val);
		//filter value
		$val = $this->onFilter($key, $val);
		//update value?
		if($this->__meta['props'][$key]['value'] != $val) {
			//cache old value
			$oldVal = $this->__meta['props'][$key]['value'];
			//set new value
			$this->__meta['props'][$key]['value'] = $val;
			//notify model
			$this->onChange($key, $val, $this->__meta['hydrating']);
			//notify orm?
			if(!$this->__meta['hydrating']) {
				$this->__orm->onChange($this, $key, $oldVal, $val);
			}
		}
	}

	public final function id() {
		return $this->{$this->__meta['id']};
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
			$this->__validator->isValid($this->__meta['props'][$key]['rules'], $val);
			//process validation errors
			foreach($this->__validator->errors() as $error) {
				$this->addError($key, $error);
			}
		}
		//validate hook
		$this->onValidate();
		//check relations
		foreach($this->__meta['relations'] as $prop => $meta) {
			//get relation
			$rel = $this->$prop;
			//can validate?
			if($meta['onValidate'] && $rel) {
				//is relation valid?
				if(!$rel->isValid()) {
					//set errors
					foreach($rel->errors() as $k => $v) {
						$this->addError($k, $v);
					}
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
			$this->__orm->hydrate($this, $where);
			//finish hydrating
			$this->__meta['hydrating'] = false;
		}
		//return
		return $this->id();
	}

	public final function set(array $data, array $opts=[]) {
		//set opts
		$opts = array_merge([
			'relFields' => [],
		], $opts);
		//setter hook
		if(!$data = $this->onSet($data)) {
			return $this;
		}
		//loop through data
		foreach($data as $k => $v) {
			//set property?
			if(isset($this->__meta['props'][$k])) {
				$this->$k = $v;
			}
		}
		//check relations
		foreach($this->__meta['relations'] as $prop => $meta) {
			//get relation
			$rel = $this->$prop;
			//can set?
			if($meta['onSet'] && $rel) {
				//set vars
				$tmp = $data;
				$field = isset($opts['relFields'][$prop]) ? $opts['relFields'][$prop] : $prop;
				//is proxy?
				if($rel instanceof Proxy) {
					$rel = $rel->__target();
				}
				//loop through data
				foreach($tmp as $k => $v) {
					//skip field?
					if(in_array($k, $meta['skipFields'])) {
						unset($tmp[$k]);
						continue;
					}
					//update data?
					if(is_array($v) && preg_match("/(^" . $field . "$)|(\_" . $field . "$)/i", $k)) {
						$tmp = $v;
						break;
					}
				}
				//is collection?
				if($rel instanceOf ModelCollection) {
					//first key
					$fk = array_key_first($tmp);
					//reset data?
					if(is_null($fk) || !is_array($tmp[$fk])) {
						$tmp = [];
					}
				}
				//set data?
				if(!empty($tmp)) {
					$rel->set($tmp, $opts);
				}
			}
		}
		//chain it
		return $this;
	}

	public final function save($delete = false) {
		//is valid?
		if(!$this->isValid()) {
			return false;
		}
		//read only?
		if($this->__meta['readonly']) {
			return ($this->id() && !$delete) ? $this->id() : false;
		}
		//set vars
		$method = $delete ? 'delete' : 'save';
		$hook = 'on' . ($delete ? 'Delete' : 'Save');
		//data saved?
		if(!$id = $this->__orm->$method($this)) {
			$this->addError('unknown', 'Unable to ' . $method . ' record. Please try again.');
			return false;
		}
		//currently processing?
		if(!$this->__meta['processing']) {
			//start saving
			$this->__meta['processing'] = true;
			//check relations
			foreach($this->__meta['relations'] as $prop => $meta) {
				//get relation
				$rel = $this->$prop;
				//can save?
				if($meta[$hook] && $rel) {
					//save relation?
					if(!$rel->$method()) {
						//set errors
						foreach($rel->errors() as $k => $v) {
							$this->addError($k, $v);
						}
					}
				}
			}
			//save hook
			$this->$hook();
			//finish saving
			$this->__meta['processing'] = false;
		}
		//return
		return $this->__meta['errors'] ? false : ($delete ? true : $id);
	}

	public final function delete() {
		return $this->save(true);
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

	protected function onTypecast($key, $val) {
		//set vars
		$type = $this->__meta['props'][$key]['type'];
		$orgVal = $this->__meta['props'][$key]['value'];
		//scalar mis-match?
		if(!is_null($orgVal) && is_scalar($orgVal) !== is_scalar($val)) {
			return $orgVal;
		}
		//cast by type
		if($type === 'string') {
			$val = trim($val);
		} else if($type === 'integer') {
			$val = $val ?: 0;
		} else if($type === 'double') {
			$val = $val ?: 0;
		} else if($type === 'array') {
			$val = $val ?: [];
		} else {
			$val = $val ?: NULL;
		}
		//return
		return $val;
	}

	protected function onFilter($key, $val) {
		return $val;
	}

	protected function onChange($key, $val, $isHydrating) {
		return;
	}

	protected function onValidate() {
		return;
	}

	protected function onSave() {
		return;
	}

	protected function onDelete() {
		return;
	}

	/* Static helpers */

	public final static function __meta($opts=[]) {
		//opts to array?
		if($opts && is_string($opts)) {
			$opts = [ 'key' => $opts ];
		}
		//default opts
		$opts = array_merge([
			'object' => null,
			'key' => null,
			'val' => '%%null%%',
		], $opts);
		//get class
		$class = static::class;
		//generate meta template?
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
				'processing' => false,
				'isNew' => true,
			];
			//default rel
			$defRel = [
				'model' => null,
				'where' => [],
				'type' => 'hasOne',
				'lazy' => true,
				'onSet' => true,
				'onValidate' => true,
				'onSave' => true,
				'onDelete' => true,
				'skipFields' => [],
				'if' => [],
			];
			//parse meta
			$parse = \Proto2\Reflection\Meta::parse($class);
			//loop through annotations
			foreach($parse['annotations'] as $param => $args) {
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
			//loop through properties
			foreach($parse['properties'] as $name => $prop) {
				//skip property?
				if($prop['static'] || $prop['scope'] !== 'public') {
					continue;
				}
				//add to meta data
				$meta['props'][$name] = [
					'type' => $prop['type'],
					'value' => $prop['value'],
					'null' => true,
					'filters' => [],
					'rules' => [],
				];
				//loop through property annotations
				foreach($prop['annotations'] as $param => $args) {
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
		//update object value?
		if($opts['object'] && $opts['key'] && $opts['val'] !== '%%null%%') {
			$opts['object']->__meta[$opts['key']] = $opts['val'];
		}
		//get meta data
		$meta = $opts['object'] ? $opts['object']->__meta : self::$__metaTpl[$class];
		//return
		return $opts['key'] ? (isset($meta[$opts['key']]) ? $meta[$opts['key']] : null) : $meta;
	}

}