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

	protected $routes = [
		'home' => [
			'path' => '',
			'auth' => false,
			'hide' => false,
			'methods' => [],
		],
		'unauthorized' => [
			'path' => '401',
			'auth' => false,
			'hide' => false,
			'methods' => [],
		],
		'notFound' => [
			'path' => '404',
			'auth' => false,
			'hide' => false,
			'methods' => [],
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
			//set vars
			$pRoutes = array();
			$ref = new \ReflectionObject($this);
			//get parent routes
			while($parent = $ref->getParentClass()) {
				//get default props
				$props = $parent->getDefaultProperties();
				//has routes prop?
				if($props && isset($props['routes']) && is_array($props['routes'])) {
					$pRoutes = array_merge($props['routes'], $pRoutes);
				}
				//next loop
				$ref = $parent;
			}
			//merge parent routes
			$this->routes = array_merge($pRoutes, $this->routes, $routes);
			//loop through routes
			foreach($this->routes as $method => $route) {
				//format route
				$route = array_merge([
					'path' => '',
					'auth' => null,
					'methods' => [],
					'hide' => false,
				], $route);
				//set callback
				$route['callback'] = [ $this, $method ];
				//format path
				$route['path'] = $this->basePath . ltrim($route['path'], '/');
				//format auth?
				if($route['auth'] === true) {
					$route['auth'] = [ $this, 'auth' ];
				} else if($route['auth']) {
					$route['auth'] = [ $this, $route['auth'] ];
				}
				//add dev methods?
				if($this->devMethods && $route['methods'] && $this->kernel->isEnv('dev')) {
					$route['methods'] = array_unique(array_merge($route['methods'], $this->devMethods));
				}
				//add route
				$this->kernel->route($route);
			}
		}
		//chain it
		return $this;
	}

	public function auth() {
		throw new \Exception("Please define an auth method, to use API authentication");
	}

	public function home($prefix='') {
		//set vars
		$endpoints = [];
		$prefix = trim($prefix ?: '', '/');
		//loop through routes
		foreach($this->routes as $method => $route) {
			//get path
			$path = $route['path'];
			//skip display?
			if($route['hide']) {
				continue;
			}
			//is home?
			if(stripos($method, 'home') !== false) {
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
				$endpoints[] = $path;
			}
		}
		//send response
		$this->respond([
			'code' => 200,
			'data' => [
				'endpoints' => $endpoints,
			],
		]);
	}

	public function unauthorized() {
		$this->respond([
			'code' => 401,
		]);
	}

	public function notFound() {
		$this->respond([
			'code' => 404,
		]);
	}

	protected function addData($key, $val) {
		$this->data[$key] = $val;
	}

	protected function addError($key, $val) {
		$this->errors[$key] = $val;
	}

	protected function respond(array $response, array $auditData=[]) {
		//format response
        $response = $this->formatResponse($response);
        //audit response
        $this->auditLog($response, $auditData);
        //send response
        $this->kernel->json($response);
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