<?php

namespace Prototypr;

class Route implements \ArrayAccess {

	use ConstructTrait;

	public $path = '';
	public $methods = [];
	public $callback = null;
	public $params = [];
	public $auth = null;
	public $hide = false;

	protected $inputFields = [];

	#[\ReturnTypeWillChange]
	public function offsetExists($offset) {
		return isset($this->$offset);
	}

	#[\ReturnTypeWillChange]
	public function offsetGet($offset) {
		return isset($this->$offset) ? $this->$offset : null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet($offset, $value) {
		$this->$offset = $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset($offset) {
		$this->$offset = null;
	}

	public function doCallback() {
		//set vars
		$input = [];
		$output = [ 'code' => 200, 'errors' => [], 'data' => null ];
		//loop through input fields
		foreach($this->inputFields as $field => $meta) {
			//format meta
			$meta = array_merge([
				'source' => 'REQUEST',
				'required' => false,
				'default' => null,
				'rules' => [],
				'filters' => [],
			], $meta);
			//log errors
			$errors = [];
			//get source
			$source = '_' . strtoupper($meta['source']);
			//get value
			$value = isset($GLOBALS[$source][$field]) ? $GLOBALS[$source][$field] : null;
			//translate value?
			if($value === 'true') $value = true;
			if($value === 'false') $value = false;
			if($value === 'null') $value = null;
			//is required?
			if($meta['required'] && !in_array('required', $meta['rules'])) {
				$meta['rules'][] = 'required';
			}
			//process custom rules
			foreach($meta['rules'] as $rule) {
				$this->kernel->validator->isValid($rule, $value);
			}
			//process validation errors
			foreach($this->kernel->validator->errors() as $error) {
				$errors[] = $error;
			}
			//validate hook
			$errors = $this->onValidate($field, $value, $errors);
			//has errors?
			if(!empty($errors)) {
				//cache errors
				$output['errors'][$field] = $errors;
			} else {
				//set default?
				if($value === null || $value === '') {
					$value = $meta['default'];
				}
				//process custom filters
				foreach($meta['filters'] as $filter) {
					$value = $this->kernel->validator->filter($filter, $value);
				}
				//filters hook
				$input[$field] = $this->onFilter($field, $value);
			}
		}
		//has errors?
		if($output['errors']) {
			//client error
			$output['code'] = 400;
		} else {
			try {
				//execute route
				$tmp = $this->doRoute($input, $output);
				//update output?
				if($tmp !== null) {
					$output = $tmp;
				}
			} catch(\Exception $e) {
				//server error
				$output['code'] = 500;
			}
		}
		//return
		return $output;
	}

	protected function onConstruct(array $opts) {
		$this->callback = [ $this, 'doCallback' ];
	}

	protected function onValidate($field, $value, array $errors) {
		return $errors;
	}

	protected function onFilter($field, $value) {
		return $value;
	}

	protected function doRoute(array $input, array $output) {
		return $output;
	}

}