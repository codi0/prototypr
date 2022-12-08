<?php

namespace Prototypr;

class Route implements \ArrayAccess {

	use ConstructTrait;

	public $path = '';
	public $methods = [ 'GET' ];
	public $callback = null;
	public $params = [];

	public $auth = null;
	public $public = true;

	protected $inputSchema = [];
	protected $outputSchema = [];
	protected $errors = [];

	protected $reqMethod = 'GET';
	protected $restMethods = [ 'GET', 'POST', 'PUT', 'DELETE' ];

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

	public function describe($method = null) {
		//set vars
		$input = $this->inputSchema;
		$output = $this->outputSchema;
		$method = strtoupper($method ?: '');
		//filter by method?
		if(!empty($method)) {
			//valid method?
			if(!in_array($method, $this->methods)) {
				return [];
			}
			//loop through input
			foreach($input as $field => $meta) {
				//remove field?
				if(!isset($meta['contexts'][$method])) {
					unset($input[$field]);
					continue;
				}
				//add to top level
				$input[$field] = array_merge($input[$field], $input[$field]['contexts'][$method]);
				//remove contexts
				unset($input[$field]['contexts']);
			}
		}
		//return
		return [
			'url' => $this->kernel->api->getUrl($this->path),
			'path' => $this->path,
			'methods' => $this->methods,
			'auth' => !!$this->auth,
			'public' => !!$this->public,
			'input_schema' => $input,
			'output_schema' => $output,
		];
	}

	public function doCallback() {
		//set vars
		$input = [];
		$output = [ 'code' => 200, 'errors' => [], 'data' => null ];
		//set properties
		$this->errors = [];
		$this->reqMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		//loop through input schema
		foreach($this->inputSchema as $field => $meta) {
			//valid context?
			if(!isset($meta['contexts'][$this->reqMethod])) {
				continue;
			}
			//input vars
			$errors = [];
			$type = explode('.', $meta['type'])[0];
			$context = $meta['contexts'][$this->reqMethod];
			$source = '_' . $this->mapSource($context['source']);
			$value = isset($GLOBALS[$source][$field]) ? $GLOBALS[$source][$field] : $context['default'];
			//translate value?
			if($value === 'true') $value = true;
			if($value === 'false') $value = false;
			//set required rule?
			if($context['required'] && !in_array('required', $meta['rules'])) {
				$meta['rules'][] = 'required';
			}
			//set type rule?
			if($type && !in_array($type, $meta['rules']) && $value !== null) {
				$meta['rules'][] = $type;
			}
			//process custom rules
			foreach($meta['rules'] as $rule) {
				$this->kernel->validator->isValid($rule, $value);
			}
			//process validation errors
			foreach($this->kernel->validator->errors() as $error) {
				$this->addError($field, $error);
			}
			//cache value?
			if($value !== null) {
				$input[$field] = $value;
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
				//process custom filters
				foreach($meta['filters'] as $filter) {
					$value = $this->kernel->validator->filter($filter, $value);
				}
				//filter hook
				$input[$field] = $this->onFilter($field, $value);
			}
			try {
				//get method
				$doMethod = 'do' . ucfirst(strtolower($this->reqMethod));
				//method exists?
				if(!method_exists($this, $doMethod)) {
					$doMethod = 'doRoute';
				}
				//execute route
				$tmp = $this->$doMethod($input, $output);
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
		$defContexts = [];
		$this->callback = [ $this, 'doCallback' ];
		$this->methods = array_map('strtoupper', $this->methods);
		//set default contexts
		foreach($this->methods as $m) {
			$defContexts[$m] = [
				'required' => false,
				'source' => in_array($m, [ 'GET', 'DELETE' ]) ? 'url' : 'body',
			];
		}
		//loop through input schema
		foreach($this->inputSchema as $field => $meta) {
			//format meta
			$this->inputSchema[$field] = array_merge([
				'label' => ucfirst(str_replace('_', ' ', $field)),
				'desc' => '',
				'type' => 'string',
				'contexts' => $defContexts,
				'rules' => [],
				'filters' => [],
			], $meta);
			//check contexts
			foreach($this->inputSchema[$field]['contexts'] as $method => $context) {
				//valid method?
				if(!in_array($method, $this->restMethods)) {
					throw new \Exception("Input scheme context must be one of " . implode(', ', $this->restMethods));
				}
				//valid context?
				if(!isset($context['required']) || !isset($context['source'])) {
					throw new \Exception("Input scheme context must contain 'required' and 'source' parameters");
				}
				//set default value?
				if(!isset($context['default'])) {
					$this->inputSchema[$field]['contexts'][$method]['default'] = null;
				}
			}
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

	protected function mapSource($source) {
		//is url?
		if($source === 'url') {
			return 'GET';
		}
		//is body?
		if($source === 'body') {
			return 'POST';
		}
		//is any?
		if($source === 'any') {
			return 'REQUEST';
		}
		//default
		return strtoupper($source);
	}

}