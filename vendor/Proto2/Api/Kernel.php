<?php

namespace Proto2\Api;

class Kernel {

	protected $baseUrl = '';
	protected $basePath = '';
	protected $responseClass = 'Proto2\Http\Response';

	protected $enabled = false;

	protected $router;
	protected $eventManager;

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
		//has base path?
		if($this->basePath) {
			//format base path
			$this->basePath = trim($this->basePath, '/');
			//is path match?
			if($this->router->checkPath($this->basePath)) {
				//set flag
				$this->enabled = true;
				//register home route
				$this->router->add($this->basePath, function($req, $res) {
					return $this->routeHome($req, $res);
				});
				//override 404 route
				$this->router->add('404', function($req, $res) {
					return $this->route404($req, $res);
				});
				//override 500 route
				$this->router->add('500', function($errMsg, $ex) {
					header("Content-Type: application/json");
					return $this->route500($errMsg, $ex);
				});
			}
		}
	}

	public function isApi() {
		return $this->router->checkPath($this->basePath);
	}

	public function auth() {
		throw new \Exception("Define an inherited auth method, to use API authentication");
	}

	public function process($request, $next) {
		//set vars
		$response = null;
		$route = $request->getAttribute('route');
		//not api request?
		if(!$route || !$this->enabled) {
			return $next($request);
		}
		//process before response
		$request = $this->beforeResponse($request);
		//authenticate request?
		if($auth = $route->getAttribute('auth')) {
			//use default auth?
			if($auth === true) {
				$auth = [ $this, 'auth' ];
			}
			//auth failed?
			if(call_user_func($auth) === false) {
				//get response class
				$class = $this->responseClass;
				//build response
				$response = (new $class)->write([
					'code' => 403,
					'errors' => [ 'Authentication failed' ],
				]);
			}
		}
		//get response?
		if(!$response) {
			$response = $next($request);
		}
		//format response
		$response = $this->formatResponse($response);
		//process after response
		$response = $this->afterResponse($request, $response);
		//return
		return $response;
	}

	protected function beforeResponse($request) {
		return $request;
	}

	protected function formatResponse($response) {
		//read response body
		$json = $response->read();
		//decode body?
		if(is_string($json)) {
			if($tmp = @json_decode($json, true)) {
				$json = $tmp;
			} else if($json) {
				$json = [ 'data' => $json ];
			}
		}
		//bad request?
		if(!$json) {
			$json = [ 'code' => 400 ];
		}
		//is valid format?
		if(!is_array($json)) {
			throw new \Exception("API response body must be an array");
		}
		//set defaults
		$json = array_merge([
			'code' => 0,
			'data' => [],
			'errors' => [],
		], $json);
		//is code set?
		if(!$json['code']) {
			$json['code'] = $json['errors'] ? 400 : ($json['data'] ? 200 : 500);
		}
		//is success?
		if($json['code'] == 200) {
			//cast to array?
			if(!is_array($json['data'])) {
				$json['data'] = (array) ($json['data'] ?: []);
			}
			//remove errors
			unset($json['errors']);
		} else {
			//cast to array?
			if(!is_array($json['errors'])) {
				$json['errors'] = (array) ($json['errors'] ?: []);
			}
			//remove data
			unset($json['data']);
			//remove errors?
			if(!$json['errors']) {
				unset($json['errors']);
			}
		}
		//sort by key
		ksort($json);
		//dispatch event?
		if($this->eventManager) {
			//filter json
			$e = $this->eventManager->dispatch('api.json', [
				'json' => $json,
			]);
			//update json?
			if($tmp = $e->json) {
				$json = $tmp;
			}
		}
		//overwite response body
		$response = $response->write(json_encode($json, JSON_PRETTY_PRINT));
		//set response headers
		$response = $response->withStatus($json['code'])->withHeader('Content-Type', 'application/json');
		//return
		return $response;
	}

	protected function afterResponse($request, $response) {
		return $response;
	}

	protected function routeHome($request, $response) {
		//set vars
		$endpoints = [];
		//loop through routes
		foreach($this->router->getAll() as $name => $route) {
			//matches base path?
			if(strpos($name, $this->basePath . '/') !== 0) {
				continue;
			}
			//hide route?
			if($route->getAttribute('hidden')) {
				continue;
			}
			//add endpoint?
			if($path = $route->getName()) {
				$endpoints[$path] = $route->getMethods();
			}
		}
		//return
		return [
			'code' => 200,
			'data' => [
				'base_url' => rtrim($this->baseUrl, '/') . '/',
				'endpoints' => $endpoints,
			],
		];
	}

	protected function route404($request, $response) {
		//return
		return [
			'code' => 404,
			'errors' => [
				'API endpoint not found',
			],
		];
	}

	protected function route500($errMsg, $ex) {
		//json header
		header("Content-Type: application/json");
		//return
		return json_encode([
			'code' => 500,
			'errors' => [
				'Internal server error',
			],
		]);
	}

}