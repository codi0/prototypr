<?php

namespace Prototypr;

class Api {

	use ConstructTrait;

	protected $data = [];
	protected $errors = [];
	protected $devMethods = [];

	protected $basePath = '/';
	protected $jsonReqBody = '';
	protected $hasRun = false;

	protected $routes = [];

	protected $baseRoutes = [
		[
			'path' => '',
			'methods' => [],
			'callback' => [ '$this', 'home' ],
			'auth' => false,
			'hide' => true,
		],
		[
			'path' => '401',
			'methods' => [],
			'callback' => [ '$this', 'unauthorized' ],
			'auth' => false,
			'hide' => true,
		],
		[
			'path' => '404',
			'methods' => [],
			'callback' => [ '$this', 'notfound' ],
			'auth' => false,
			'hide' => true,
		],
	];

	public function init(array $routes=[]) {
		//has run?
		if(!$this->hasRun) {
			//update flag
			$this->hasRun = true;
			//check body for json
			if($body = file_get_contents('php://input')) {
				//attempt decode
				$decode = json_decode($body, true);
				//is json?
				if(is_array($decode)) {
					$_POST = $decode;
					$_REQUEST = array_merge($_GET, $_POST, $_COOKIE);
					$this->jsonReqBody = $body;
				}
			}
			//merge base routes
			$this->routes = array_merge($this->baseRoutes, $this->routes);
			//loop through routes
			foreach($this->routes as $index => $route) {
				//format route
				$route = $this->formatRoute($route);
				//unset index?
				if($index != $route['path']) {
					unset($this->routes[$index]);
				}
			}
		}
		//chain it
		return $this;
	}

	public function respond(array $response, array $auditData=[]) {
		//format response
        $response = $this->formatResponse($response);
        //api response event
        $response = $this->kernel->event('api.response', $response);
        //audit response
        $this->auditLog($response, $auditData);
        //send response
        $this->kernel->json($response);
	}

	public function auth() {
		throw new \Exception("Define an inherited auth method, to use API authentication");
	}

	public function home($prefix='') {
		//set vars
		$endpoints = [];
		$prefix = $this->formatPath($prefix ?: '');
		//loop through routes
		foreach($this->routes as $route) {
			//set vars
			$path = $route['path'];
			//skip display?
			if($route['hide']) {
				continue;
			}
			//has prefix?
			if($prefix && stripos($path, $prefix) !== 0) {
				continue;
			}
			//format path
			$path = substr($path, strlen($prefix));
			$path = trim($path, '/');
			//add endpoint?
			if(!empty($path)) {
				$endpoints[$path] = $route['methods'];
			}
		}
		//return
		return [
			'code' => 200,
			'data' => [
				'endpoints' => $endpoints,
			],
		];
	}

	public function unauthorized() {
		//return
		return [
			'code' => 401,
		];
	}

	public function notfound() {
		//return
		return [
			'code' => 404,
		];
	}

	public function getUrl($path) {
		return rtrim($this->kernel->config('base_url'), '/') . '/' . $this->formatPath($path);
	}

	public function addEndpoint($path, $callback=null, array $route=[]) {
		//add directly?
		if(is_array($path) || is_object($path)) {
			$route = $path;
		} else {
			//set vars
			$route['callback'] = $callback;
			$route['path'] = $path;
		}
		//format route
		return $this->formatRoute($route);
	}

	public function describe($path, $public = false) {
		//format path
		$path = $this->formatPath($path);
		//route exists?
		if(!isset($this->routes[$path])) {
			return [];
		}
		//get route
		$route = $this->routes[$path];
		//actions
		$actions = [
			'auth' => 'bool',
			'hide' => 'bool',
			'input_schema' => 'array',
			'output_schema' => 'array',
			'callback' => 'unset',
		];
		//is public?
		if($public) {
			$actions['hide'] = 'unset';
		}
		//is object?
		if(is_object($route)) {
			$route = $route->describe();
		}
		//loop through actions
		foreach($actions as $key => $action) {
			//select action
			if($action === 'unset') {
				//unset key?
				if(array_key_exists($key, $route)) {
					unset($route[$key]);
				}
			} else {
				//set key?
				if(!isset($route[$key])) {
					$route[$key] = null;
				}
				//is bool?
				if($action === 'bool') {
					$route[$key] = !!$route[$key];
				}
				//is array?
				if($action === 'array') {
					$route[$key] = (array) ($route[$key] ?: []);
				}
			}
		}
		//loop through input schema
		foreach($route['input_schema'] as $field => $meta) {
			//is public?
			if($public) {
				unset($meta['rules'], $meta['filters']);
				$route['input_schema'][$field] = $meta;
			}
		}
		//set url?
		if(!isset($route['url'])) {
			$route['url'] = $this->getUrl($route['path']);
		}
		//return
		return $route;
	}

	protected function addData($key, $val) {
		$this->data[$key] = $val;
	}

	protected function addError($key, $val) {
		$this->errors[$key] = $val;
	}

	protected function formatPath($path, $withPrefix = true) {
		//set vars
		$path = trim($path, '/');
		$prefix = trim($this->basePath, '/');
		//add previx?
		if($prefix && strpos($path, $prefix) !== 0) {
			$path = $prefix . ($path ? '/' . $path : '');
		}
		//remove prefix?
		if(!$withPrefix && $prefix && $path) {
			$path = substr($path, strlen($prefix));
			$path = trim($path, '/');
		}
		//return
		return $path;
	}

	protected function formatRoute($route) {
		//set vars
		$ctx = $this;
		$isDescribe = isset($_GET['describe']);
		//is array?
		if(is_array($route)) {
			//set array defaults
			$route = array_merge([
				'path' => '',
				'methods' => [],
				'callback' => null,
				'auth' => null,
				'hide' => false,
			], $route);
		} else if(is_string($route)) {
			//create object
			$route = new $route;
		}
		//format path
		$route['path'] = $this->formatPath($route['path']);
		//add dev methods?
		if($this->devMethods && $route['methods'] && $this->kernel->isEnv('dev')) {
			$route['methods'] = array_unique(array_merge($route['methods'], $this->devMethods));
		}
		//replace $this?
		if($route['callback'] && is_array($route['callback'])) {
			if($route['callback'][0] === '$this') {
				$route['callback'][0] = $this;
			}
		}
		//bind callback to $this
		$cb = $this->kernel->bind($route['callback'], $this);
		//wrap callback
		$route['callback'] = function() use($cb, $ctx) {
			//buffer
			ob_start();
			//execute callback
			$res = call_user_func($cb);
			//display response?
			if($echo = ob_get_clean()) {
				echo $echo;
				return;
			}
			//valid response?
			if(!is_array($res)) {
				throw new \Exception("API endpoint route must return an array");
			}
			//respond
			return $ctx->respond($res);
		};
		//format auth?
		if($route['auth'] === true) {
			$route['auth'] = [ $this, 'auth' ];
		} else if($route['auth'] && !is_callable($route['auth'])) {
			$route['auth'] = [ $this, $route['auth'] ];
		}	
		//cache using path
		$this->routes[$route['path']] = $route;
		//add route?
		if($r = (array) $route) {
			//describe API endpoint?
			if($isDescribe && !$route['hide']) {
				//reset vars
				$r['auth'] = false;
				$r['methods'] = [ 'GET' ];
				//wrap describe method
				$r['callback'] = function() use($ctx, $route) {
					//get data
					$data = $ctx->describe($route['path'], true);
					//return
					return $ctx->respond([
						'code' => $data ? 200 : 404,
						'data' => $data,
					]);
				};
			}
			//add route
			$this->kernel->route($r);
		}
		//return
		return $route;
	}

	protected function formatResponse(array $response) {
		//set defaults
		$response = array_merge([
			'code' => 0,
			'data' => [],
			'errors' => [],
		], $response);
		//is code set?
		if(!$response['code']) {
			$response['code'] = ($this->errors || $response['errors']) ? 400 : 500;
		}
		//is success?
		if($response['code'] == 200) {
			//cast to array?
			if(!is_array($response['data'])) {
				$response['data'] = (array) ($response['data'] ?: []);
			}
			//add data params
			foreach($this->data as $k => $v) {
				$response['data'][$k] = $v;
			}
			//remove errors
			unset($response['errors']);
		} else {
			//cast to array?
			if(!is_array($response['errors'])) {
				$response['errors'] = (array) ($response['errors'] ?: []);
			}
			//add error params
			foreach($this->errors as $k => $v) {
				$response['errors'][$k] = $v;
			}
			//remove data
			unset($response['data']);
			//remove errors?
			if(!$response['errors']) {
				unset($response['errors']);
			}
		}
		//reset vars
		$this->data = [];
		$this->errors = [];
		//add debug?
		if($this->kernel->isEnv('dev')) {
			$response['debug'] = $this->kernel->debug();
		}
		//sort by key
		ksort($response);
		//return
		return $response;
	}

	protected function auditLog(array $response, array $auditData=[]) {
		return;
	}

}