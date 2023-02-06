<?php

namespace Prototypr;

class Api {

	use ConstructTrait;

	protected $data = [];
	protected $errors = [];

	protected $basePath = '/';
	protected $hasRun = false;
	protected $schemaRequest = false;

	protected $rawBody = '';
	protected $rawBodyFormat = 'form';

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

	public function init($basePath=null) {
		//has run?
		if(!$this->hasRun) {
			//update flag
			$this->hasRun = true;
			$this->basePath = $basePath ?: $this->basePath;
			//check for schema request
			$segs = explode('/', $this->kernel->config('pathinfo'));
			$lastSeg = explode('.', array_pop($segs), 2);
			//schema request?
			if($lastSeg[0] === 'schema') {
				$this->schemaRequest = (isset($lastSeg[1]) && $lastSeg[1]) ? $lastSeg[1] : true;
			}
			//check raw body
			if($this->rawBody = file_get_contents('php://input')) {
				//set vars
				$bodyArr = [];
				//is json?
				if($json = json_decode($this->rawBody, true)) {
					$bodyArr = $json;
					$this->rawBodyFormat = 'json';
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
				if(!$route || $index != $route['path']) {
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
			if($path = $this->getPath($route['path'], false)) {
				$endpoints[$path] = $route['methods'];
			}
		}
		//return
		return [
			'code' => 200,
			'data' => [
				'base_url' => $this->getUrl('/'),
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
		return $path;
	}

	public function getUrl($path) {
		$path = $this->getPath($path, true);
		return trim($this->kernel->config('base_url'), '/') . '/' . trim($path, '/');
	}

	public function addEndpoint($path, $callback=null, array $route=[]) {
		//add directly?
		if($callback === null) {
			$route = $path;
		} else {
			//set vars
			$route['callback'] = $callback;
			$route['path'] = $path;
		}
		//format route
		$this->formatRoute($route);
		//return
		return true;
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
		//has run?
		if(!$this->hasRun) {
			//add route for later?
			if(!in_array($route, $this->routes)) {
				$this->routes[] = $route;
			}
			//stop
			return;
		}
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
		$route['path'] = $this->getPath($route['path'], true);
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
		$cb = $this->kernel->bind($route['callback']);
		//wrap callback
		$route['callback'] = function($params=[]) use($cb, $ctx) {
			//set vars
			$code = 0;
			$errors = [];
			$data = null;
			//begin
			try {
				//execute callback
				$res = call_user_func($cb, $params);
				//is array?
				if(is_array($res)) {
					//get vars
					foreach([ 'code', 'errors', 'data' ] as $k) {
						if(isset($res[$k]) && $res[$k]) {
							$$k = $res[$k];
						}
					}
				}
				//set data
				if(!$code && !$errors && !$data) {
					$data = $res;
				}
			} catch(\Exception $e) {
				//update error code?
				if($c = $e->getCode()) {
					if($c >= 100 && $c < 600) {
						$code = intval($c);
					}
				}
				//set error code?
				if(!$code && !$errors) {
					$code = 500;
				}
				//add error?
				if($code < 500 || $this->isDebug()) {
					$errors[] = $e->getMessage();
				}
				//log exception
				$this->logException($e, false);
			}
			//create response
			return $ctx->respond([
				'code' => $code ? $code : ($errors ? 400 : ($data ? 200 : 500)),
				'errors' => $errors,
				'data' => $data,
			]);
		};
		//format auth?
		if($route['auth'] === true) {
			$route['auth'] = [ $this, 'auth' ];
		} else if($route['auth'] && !is_callable($route['auth'])) {
			$route['auth'] = [ $this, $route['auth'] ];
		}
		//show API endpoint schema?
		if($this->schemaRequest && $route['public']) {
			//has schema?
			if(isset($route['schema'])) {
				//reset vars
				$route['auth'] = false;
				$route['schema'] = $this->schemaRequest;
				$route['methods_org'] = $route['methods'];
				$route['methods'] = [ 'GET' ];
			} else {
				$route = [];
			}
		}
		//add route?
		if(!empty($route)) {
			//cache by path
			$this->routes[$route['path']] = $route;
			//attach to router
			$this->kernel->route($route);
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