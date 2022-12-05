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
			'hide' => false,
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
				//replace $this?
				if($route['callback'] && is_array($route['callback'])) {
					if($route['callback'][0] === '$this') {
						$route['callback'][0] = $this;
					}
				}
				//cache callback
				$ctx = $this;
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
				//add dev methods?
				if($this->devMethods && $route['methods'] && $this->kernel->isEnv('dev')) {
					$route['methods'] = array_unique(array_merge($route['methods'], $this->devMethods));
				}
				//format path
				$route['path_org'] = $route['path'];
				$route['path'] = $this->basePath . ltrim($route['path'], '/');
				//cache route
				$this->routes[$index] = $route;
				//add route
				$this->kernel->route($route);
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
		$prefix = trim($prefix ?: '', '/');
		//loop through routes
		foreach($this->routes as $route) {
			//set vars
			$path = $route['path_org'];
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
			if($path && !is_numeric($path)) {
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

	public function addEndpoint($path, $callback=null, array $meta=[]) {
		//add directly?
		if(is_array($path) || is_object($path)) {
			$this->routes[] = $path;
			return;
		}
		//is callable?
		if(!is_callable($callback)) {
			throw new \Exception("Invalid callback for API endpoint");
		}
		//set vars
		$meta['callback'] = $callback;
		$meta['path'] = trim($path, '/');
		//add route
		$this->routes[] = $meta;
	}

	protected function addData($key, $val) {
		$this->data[$key] = $val;
	}

	protected function addError($key, $val) {
		$this->errors[$key] = $val;
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