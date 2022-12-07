<?php

namespace Prototypr;

class Route implements \ArrayAccess {

	use ConstructTrait;

	public $path = '';
	public $methods = [];
	public $callback = null;
	public $params = [];

	public $auth = null;
	public $public = true;

	protected $inputSchema = [];
	protected $outputSchema = [];

	protected $errors = [];

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

	public function describe() {
		return [
			'url' => $this->kernel->api->getUrl($this->path),
			'path' => $this->path,
			'methods' => $this->methods,
			'auth' => !!$this->auth,
			'public' => !!$this->public,
			'input_schema' => $this->inputSchema,
			'output_schema' => $this->outputSchema,
		];
	}

	public function doCallback() {
		//set vars
		$input = [];
		$output = [ 'code' => 200, 'errors' => [], 'data' => null ];
		$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		//reset errors
		$this->errors = [];
		//loop through input schema
		foreach($this->inputSchema as $field => $meta) {
			//valid context?
			if(!isset($meta['contexts'][$method])) {
				continue;
			}
			//input vars
			$errors = [];
			$source = '_' . strtoupper($meta['source']);
			$value = isset($GLOBALS[$source][$field]) ? $GLOBALS[$source][$field] : null;
			$isRequired = ($meta['contexts'][$method] === 'required') || ($meta['contexts'][$method] === true);
			//translate value?
			if($value === 'true') $value = true;
			if($value === 'false') $value = false;
			if($value === 'null') $value = null;
			//cache value
			$input[$field] = $value;
			//required field?
			if($isRequired && !in_array('required', $meta['rules'])) {
				$meta['rules'][] = 'required';
			}
			//process custom rules
			foreach($meta['rules'] as $rule) {
				$this->kernel->validator->isValid($rule, $value);
			}
			//process validation errors
			foreach($this->kernel->validator->errors() as $error) {
				$this->addError($field, $error);
			}
		}
		//validate hook
		$this->onvalidate($input);
		//has errors?
		if($this->errors) {
			//client error
			$output['code'] = 400;
			$output['errors'] = $this->errors;
		} else {
			//filter input
			foreach($input as $field => $value) {
				//set default?
				if($value === null || $value === '') {
					$value = $meta['default'];
				}
				//process custom filters
				foreach($meta['filters'] as $filter) {
					$value = $this->kernel->validator->filter($filter, $value);
				}
				//filter hook
				$input[$field] = $this->onFilter($field, $value);
			}
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
		//set vars
		$contexts = [];
		$this->callback = [ $this, 'doCallback' ];
		//set default contexts
		foreach($this->methods as $m) {
			$contexts[$m] = 'optional';
		}
		//loop through input schema
		foreach($this->inputSchema as $field => $meta) {
			//format meta
			$this->inputSchema[$field] = array_merge([
				'label' => ucfirst(str_replace('_', ' ', $field)),
				'desc' => '',
				'contexts' => $contexts,
				'source' => 'REQUEST',
				'type' => 'string',
				'default' => null,
				'rules' => [],
				'filters' => [],
			], $meta);
		}
	}

	protected function onValidate(array $input) {
		return;
	}

	protected function onFilter($field, $value) {
		return $value;
	}

	protected function doRoute(array $input, array $output) {
		return $output;
	}

	protected function addError($field, $message) {
		//create array?
		if(!isset($this->errors[$field])) {
			$this->errors[$field] = [];
		}
		//add error message
		$this->errors[$field][] = $message;
	}

}