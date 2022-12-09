<?php

namespace Prototypr;

class Api {

	use ConstructTrait;

	protected $data = [];
	protected $errors = [];

	protected $basePath = '/';
	protected $rawBody = '';
	protected $hasRun = false;

	protected $routes = [];

	protected $baseRoutes = [
		[
			'path' => '',
			'methods' => [],
			'callback' => [ '$this', 'home' ],
			'auth' => false,
			'public' => false,
		],
		[
			'path' => '401',
			'methods' => [],
			'callback' => [ '$this', 'unauthorized' ],
			'auth' => false,
			'public' => false,
		],
		[
			'path' => '404',
			'methods' => [],
			'callback' => [ '$this', 'notfound' ],
			'auth' => false,
			'public' => false,
		],
	];

	public function init(array $routes=[]) {
		//has run?
		if(!$this->hasRun) {
			//update flag
			$this->hasRun = true;
			//check raw body
			if($this->rawBody = file_get_contents('php://input')) {
				//set vars
				$bodyArr = [];
				//is json?
				if($json = json_decode($this->rawBody, true)) {
					$bodyArr = $json;
				} else if(!$_POST) {
					parse_str($this->rawBody, $bodyArr);
				}
				//update $_POST?
				if($bodyArr && is_array($bodyArr)) {
					$_POST = $bodyArr;
					$_REQUEST = array_merge($_GET, $_POST, $_COOKIE);
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

	public function home() {
		//set vars
		$endpoints = [];
		//loop through routes
		foreach($this->routes as $route) {
			//skip display?
			if(!$route['public']) {
				continue;
			}
			//add endpoint?
			if($path = $this->getPath($route['path'], true)) {
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

	public function getPath($path, $withBase = true) {
		//set vars
		$path = trim($path, '/');
		$base = trim($this->basePath, '/');
		//add base path?
		if($base && strpos($path, $base) !== 0) {
			$path = $base . ($path ? '/' . $path : '');
		}
		//remove base path?
		if(!$withBase && $base && $path) {
			$path = substr($path, strlen($base));
			$path = trim($path, '/');
		}
		//return
		return $path ?: '/';
	}

	public function getUrl($path) {
		return rtrim($this->kernel->config('base_url'), '/') . '/' . $this->getPath($path);
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

	public function describe($path, $method = null, $public = false) {
		//format path
		$path = $this->getPath($path, false);
		//route exists?
		if(!isset($this->routes[$path])) {
			return [];
		}
		//get route
		$route = $this->routes[$path];
		//actions
		$actions = [
			'auth' => 'bool',
			'public' => 'bool',
			'input_schema' => 'array',
			'output_schema' => 'array',
			'callback' => 'unset',
		];
		//is public?
		if($public) {
			$actions['public'] = 'unset';
		}
		//is object?
		if(is_object($route)) {
			$route = $route->describe($method);
		}
		//empty route?
		if(empty($route)) {
			return [];
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
				'public' => true,
			], $route);
		} else if(is_string($route)) {
			//create object
			$route = new $route;
		}
		//replace hide?
		if(isset($route['hide'])) {
			$route['public'] = !$route['hide'];
			unset($route['hide']);
		}
		//format path
		$route['path'] = $this->getPath($route['path'], false);
		//replace object strings
		foreach([ 'callback', 'auth' ] as $k) {
			//try replacing?
			if($route[$k] && is_array($route[$k])) {
				//replace $this?
				if($route[$k][0] === '$this') {
					$route[$k][0] = $this;
				}
				//replace $kernel?
				if($route[$k][0] === '$kernel') {
					$route[$k][0] = $this->kernel;
				}
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
			//full path
			$r['path'] = $this->getPath($route['path'], true);
			//describe API endpoint?
			if($isDescribe && $route['public']) {
				//reset vars
				$r['auth'] = false;
				$r['methods'] = [ 'GET' ];
				//wrap describe method
				$r['callback'] = function() use($ctx, $route) {
					//get data
					$data = $ctx->describe($route['path'], $_GET['describe'], true);
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