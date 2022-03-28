<?php

namespace Prototypr;

class Validator {

	protected $rules = [];
	protected $filters = [];

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
		//get methods
		$methods = get_class_methods($this);
		//loop through methods
		foreach($methods as $method) {
			//is rule?
			if(strpos($method, 'rule') === 0) {
				$name = lcfirst(substr($method, 4));
				$this->rules[$name] = [ $this, $method ];
			}
			//is filter?
			if(strpos($method, 'filter') === 0) {
				$name = lcfirst(substr($method, 6));
				$this->filters[$name] = [ $this, $method ];
			}
		}
	}

	public function addRule($name, $fn) {
		$this->rules[$name] = $fn;
	}

	public function addFilter($name, $fn) {
		$this->filters[$name] = $fn;
	}

	public function isValid($rule, $value, &$error='') {
		//set vars
		$args = [];
		//has args?
		if(strpos($rule, '(') !== false) {
			list($rule, $args) = explode('(', $rule);
			$args = array_map('trim', explode(',', trim(')', $args)));
		}
		//rule exists?
		if(!isset($this->rules[$rule])) {
			throw new \Exception("Validation rule $rule does not exist");
		}
		//is callable?
		if(!is_callable($this->rules[$rule])) {
			throw new \Exception("Validation rule $rule is not a valid callable");
		}
		//execute callback
		$error = trim(call_user_func($this->rules[$rule], $value, ...$args));
		//return
		return empty($error);
	}

	public function filter($filter, $value) {
		//has filter?
		if(is_string($filter) && isset($this->filters[$filter])) {
			$filter = $this->filters[$filter];
		}
		//is callable?
		if(!is_callable($filter)) {
			throw new \Exception("Filter is not a valid callable");
		}
		//execute callback
		return call_user_func($filter, $value);
	}

	protected function ruleRequired($value) {
		if(empty($value)) {
			return 'required field';
		}
	}

	protected function ruleId($value) {
		if($value && !preg_match('/^[0-9]+$/', $value)) {
			return 'numeric ID required';
		}
	}

	protected function ruleDigits($value, $length=null) {
		//set args
		$r = $length ? '{' . $length . '}' : '+';
		//error found?
		if($value && !preg_match('/^[0-9]' . $r . '$/', $value)) {
			return ($length ? $length . ' ' : '') . 'digits only';
		}
	}

	protected function ruleEmail($value) {
		if($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
			return 'valid email required';
		}
	}

	protected function rulePhone($value) {
		//remove whitespace
		$value = $this->filterNowhitespace($value);
		//error found?
		if($value && !preg_match('/^\+?[0-9]{4,13}$/', $value)) {
			return 'valid phone number required';
		}
	}

	protected function ruleDateFormat($value, $format='Y-m-d') {
		//has value?
		if($value) {
			//convert to datetime
			$d = \DateTime::createFromFormat($format, $value);
			//format matches?
			if(!$d || $d->format($format) !== $value) {
				return 'valid date format required (' . $format . ')';
			}
		}	
	}

	protected function filterNowhitespace($value) {
		return preg_replace('/s+/', '', $value);
	}

}