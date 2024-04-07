<?php

namespace Proto2\Api;

class Route {
	
	public $module;
	public $isPrimary;

	public $path = '';
	public $callback = null;
	public $params = [];

	public $methods = [ 'GET' ];
	public $contentTypes = [ 'application/json', 'application/x-www-form-urlencoded' ];

	public $auth = null;
	public $public = true;
	public $schema = false;

	protected $inputSchema = [];
	protected $outputSchema = [];
	protected $errors = [];

	protected $baseUrl = '';
	protected $reqMethod = 'GET';
	protected $restMethods = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ];

	protected $input;
	protected $validator;

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
		//build input schema
		$this->inputSchema = $this->buildInputSchema($this->inputSchema, $defContexts);
	}

	public function getSchema($method = null) {
		//format method
		$method = ($method && is_string($method)) ? strtoupper($method) : null;
		//return
		return [
			'base_url' => $this->baseUrl,
			'path' => $this->path,
			'methods' => $this->methods,
			'content_types' => $this->contentTypes,
			'auth' => !!$this->auth,
			'public' => !!$this->public,
			'input_schema' => self::filterInputSchema($this->inputSchema, $method),
			'output_schema' => $this->outputSchema,
		];
	}

	public function doCallback() {
		//set vars
		$input = [];
		$output = [ 'code' => 200, 'errors' => [], 'data' => null ];
		//set properties
		$this->errors = [];
		$this->reqMethod = $this->input->server('REQUEST_METHOD') ?: 'GET';
		//reset methods?
		if(isset($this->methods_org)) {
			$this->methods = $this->methods_org;
			unset($this->methods_org);
		}
		//show schema?
		if($this->schema) {
			return [
				'code' => 200,
				'data' => $this->getSchema($this->schema),
			];
		}
		//process input schema
		$input = $this->processInputSchema($this->inputSchema);
		//has errors?
		if($this->errors) {
			//client error
			$output['code'] = 400;
			$output['errors'] = $this->errors;
		} else {
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

	protected function onProcessInput(array $input) {
		return $input;
	}

	protected function doRoute(array $input, array $output) {
		return $output;
	}

	protected function addError($field, $message) {
		//add to errors
		$this->errors = $this->addToArray($this->errors, $field, $message, [
			'array' => true,
		]);
	}

	protected function mapSource($source) {
		//format source
		$source = strtoupper($source);
		//is url?
		if($source === 'URL') {
			return 'GET';
		}
		//is body?
		if($source === 'HEADER') {
			return 'SERVER';
		}
		//is body?
		if($source === 'BODY') {
			return 'POST';
		}
		//is any?
		if($source === 'ANY') {
			return 'REQUEST';
		}
		//default
		return $source;
	}

	protected function buildInputSchema(array $schema, array $defContexts=[]) {
		//loop through array
		foreach($schema as $field => $meta) {
			//has children?
			if(isset($meta['children']) && $meta['children']) {
				//format meta
				$schema[$field] = array_merge([
					'label' => ucfirst(str_replace('_', ' ', $field)),
					'multiple' => false,
				], $meta);
				//process children
				$schema[$field]['children'] = $this->buildInputSchema($meta['children'], $defContexts);
			} else {
				//format meta
				$schema[$field] = array_merge([
					'label' => ucfirst(str_replace('_', ' ', $field)),
					'desc' => '',
					'type' => 'string',
					'contexts' => $defContexts,
					'rules' => [],
					'filters' => [],
				], $meta);
				//check contexts
				foreach($schema[$field]['contexts'] as $method => $context) {
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
						$schema[$field]['contexts'][$method]['default'] = null;
					}
				}
			}
		}
		//return
		return $schema;
	}

	protected function processInputSchema(array $schema, array $input = [], $parentKey='') {
		//loop through schema
		foreach($schema as $field => $meta) {
			//update field?
			if($parentKey) {
				$field = $parentKey . '.' . $field;
			}
			//has children?
			if(isset($meta['children']) && $meta['children']) {
				//is multiple?
				if(isset($meta['multiple']) && $meta['multiple']) {
					//get tmp dataset
					$tmp = (array) ($this->input->request($field) ?: []);
					//loop through dataset
					foreach($tmp as $k => $v) {
						$input = $this->processInputSchema($meta['children'], $input, "$field.$k");
					}
				} else {
					$input = $this->processInputSchema($meta['children'], $input, $field);
				}
				//next
				continue;
			}
			//valid context?
			if(!isset($meta['contexts'][$this->reqMethod])) {
				continue;
			}
			//input vars
			$errors = [];
			$type = explode('.', $meta['type'])[0];
			$context = $meta['contexts'][$this->reqMethod];
			$source = $this->mapSource($context['source']);
			$value = $this->input->$source($field, [ 'default' => $context['default'] ]);
			//override with param?
			if(isset($this->params[$field])) {
				$value = $this->params[$field];
			}
			//translate value?
			if($value === 'true') $value = true;
			if($value === 'false') $value = false;
			//add required rule?
			if($context['required'] && !in_array('required', $meta['rules'])) {
				$meta['rules'][] = 'required';
			}
			//add type rule?
			if($type && !in_array($type, $meta['rules']) && $value !== null) {
				$meta['rules'][] = $type;
			}
			//process custom rules
			$this->validator->isValid($meta['rules'], $value);
			//process custom filters
			$value = $this->validator->filter($meta['filters'], $value);
			//process validation errors
			foreach($this->validator->errors() as $error) {
				$this->addError($field, $error);
			}
			//cache value?
			if($value !== null) {
				$input = $this->addToArray($input, $field, $value);
			}
		}
		//input hook?
		if(empty($parentKey)) {
			//execute hook
			$tmp = $this->onProcessInput($input);
			//update input?
			if(is_array($tmp)) {
				$input = $tmp;
			}
		}
		//return
		return $input;
	}

	public static function filterInputSchema(array $input, $method) {
		//set vars
		$remove = [ 'rules', 'filters' ];
		//loop through array
		foreach($input as $field => $meta) {
			//filter method?
			if($method) {
				//has children?
				if(isset($meta['children']) && $meta['children']) {
					//compile children
					$input[$field]['children'] = self::filterInputSchema($meta['children'], $method);
					//remove field?
					if(!$input[$field]['children']) {
						unset($input[$field]);
						continue;
					}					
				} else if(isset($meta['contexts']) && $meta['contexts']) {
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
			//remove vars
			foreach($remove as $k) {
				//key exists?
				if(array_key_exists($k, $meta)) {
					unset($input[$field][$k]);
				}
			}
			
		}
		//return
		return $input;
	}

	protected function addToArray(array $arr, $key, $val, array $opts=[]) {
		//default opts
		$opts = array_merge([
			'separator' => '.',
			'array' => false,
		], $opts);
		//format key?
		if(!is_array($key)) {
			$key = $key ? explode($opts['separator'], $key) : [];
		}
		//set vars
		$tmp =& $arr;
		$count = count($key);
		//loop through key
		foreach($key as $k => $v) {
			//is last segment?
			if($count == ($k+1)) {
				if($opts['array']) {
					$tmp[$v] = isset($tmp[$v]) ? $tmp[$v] : [];
					$tmp[$v][] = $val;
				} else {
					$tmp[$v] = $val;
				}
			} else {
				$tmp[$v] = isset($tmp[$v]) ? $tmp[$v] : [];
				$tmp =& $tmp[$v];
			}
		}
		//return
		return $arr;
	}

}