<?php

namespace Proto2\Route;

class Dispatcher {

	protected $routes = [];
	protected $stack = [];
	protected $lastArgs = [];

	protected $context;
	protected $responseClass = 'Proto2\Http\Response';

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
		//format routes
		foreach($this->routes as $name => $route) {
			$this->add($name, $route);
		}
	}

	public function has($name) {
		//set vars
		$name = trim($name, '/');
		//return
		return isset($this->routes[$name]);
	}

	public function get($name) {
		//set vars
		$name = trim($name, '/');
		//return
		return isset($this->routes[$name]) ? $this->routes[$name] : null;
	}

	public function getAll() {
		return $this->routes;
	}

	public function add($route, $callback=null) {
		//check route type
		if(is_array($route)) {
			//create from array
			$route = new Route($route);
		} else if(!is_object($route)) {
			//is callable?
			if(is_callable($callback)) {
				$opts = [
					'name' => $route,
					'callback' => $callback,
				];
			} else {
				$opts = (array) $callback;
				$opts['name'] = $route;
			}
			//create route
			$route = new Route($opts);
		}
		//add route
		$this->routes[trim($route->getName(), '/')] = $route;
		//chain it
		return $this;
	}

	public function match($request, $method=null) {
		//set vars
		$route = null;
		$default = null;
		//is request object?
		if(is_object($request)) {
			$uri = trim($request->getUri()->getPathInfo(), '/');
			$method = $request->getMethod();
		} else {
			$uri = trim($request, '/');
			$method = $_SERVER['REQUEST_METHOD'];
		}
		//sort routes
		uksort($this->routes, function($a, $b) use($uri) {
			return $this->sortRoutes($a, $b, $uri);
		});
		//exact match first?
		if(isset($this->routes[$uri])) {
			$this->routes = [ $uri => $this->routes[$uri] ] + $this->routes;
		}
		//loop through routes
		foreach($this->routes as $name => $r) {
			//set vars
			$params = [];
			$methods = $r->getMethods();
			//method match?
			if($methods && !in_array($method, $methods)) {
				continue;
			}
			//exact match?
			if($name == $uri) {
				$route = $r;
				break;
			}
			//default match?
			if($name == '404' || $name == '*') {
				$default = $r;
				continue;
			}
			//set target
			$target = str_replace([ '*', '..*' ], '.*', $name);
			//extract params
			foreach(explode('/', $name) as $seg) {
				//param found?
				if($seg && $seg[0] === ':') {
					$seg = str_replace([ ':', '?' ], '', $seg);
					$params[$seg] = null;
					$target = str_replace([ "/:$seg", ":$seg" ], [ "(\/.*)", "(.*)" ], $target);
				}
			}
			//build regex
			$regex = '/^' . str_replace([ '/', '\\\\' ], [ '\/', '\\' ], $target) . '$/';
			//valid route?
			if(!preg_match($regex, $uri, $matches)) {
				continue;
			}
			//set param values?
			if($params && $matches) {
				//loop through matches
				foreach($matches as $index => $match) {
					if($index > 0) {
						$key = array_keys($params)[$index-1];
						$params[$key] = trim($match, '/');
					}
				}
			}
			//set route
			$route = $r;
			$route->setParams($params, true);
			//stop
			break;
		}
		//use default?
		if($route === null) {
			//set default
			$route = $default;
			//failed?
			if($route === null) {
				throw new \Exception("No matching route found");
			}
		}
		//return
		return $route;
	}

	public function call($route, array $args=null) {
		//find route?
		if(is_string($route)) {
			$route = $this->match($route);
		}
		//valid route?
		if(!($route instanceof Route)) {
			throw new \Exception("Route not found");
		}
		//use last args?
		if($args === null) {
			$args = $this->lastArgs;
		}
		//set route?
		if($args && is_object($args[0])) {
			$args[0] = $args[0]->withAttribute('route', $route);
		}
		//start buffer
		ob_start();
		//get callback
		$callback = $route->getCallback();
		//bind context?
		if($this->context) {
			$callback = \Closure::bind($callback, $this->context, $this->context);
		}
		//invoke callback
		$output = call_user_func_array($callback, $args);
		//end buffer
		$buffer = ob_get_clean() ?: '';
		//return
		return $output ?: $buffer;
	}

	public function dispatch($request, $response=null) {
		//valid request?
		if(!is_object($request)) {
			throw new \Exception("Invalid HTTP Request object");
		}
		//create response?
		if(!is_object($response)) {
			$response = new $this->responseClass;
		}
		//get route
		$route = $this->match($request);
		//attach attributes
		$request = $request->withAttribute('route', $route);
		$request = $request->withAttribute('response', $response);
		//cache args
		$this->lastArgs = [ $request, $response ];
		//cache request?
		if(!in_array($request, $this->stack)) {
			$this->stack[] = $request;
		}
		//call route
		if($output = $this->call($route, $this->lastArgs)) {
			//update response object?
			if(is_object($output) && method_exists($output, 'getBody')) {
				$response = $output;
			} else {
				$response->write($output);
			}
		}
		//get final route
		$route = $request->getAttribute('route') ?: $route;
		//is http code?
		if($code = $route->getName()) {
			if(is_numeric($code) && $code >= 100 && $code <= 600) {
				$response = $response->withStatus($code);
			}
		}
		//return
		return $response;
	}

	public function dispatchStack($index=null) {
		//format index?
		if($index === 'first') {
			$index = 0;
		} else if($index === 'last') {
			$index = count($this->stack) - 1;
		}
		//get one?
		if($index >= 0) {
			return isset($this->stack[$index]) ? $this->stack[$index] : null;
		}
		//get all
		return $this->stack;
	}

	public function checkPath($needle, $exact=false) {
		//set vars
		$needle = trim($needle, '/');
		$pathInfo = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
		//exact match?
		if($pathInfo === $needle) {
			return true;
		}
		//starts with match?
		if(!$exact && strpos($pathInfo, $needle . '/') === 0) {
			return true;
		}
		//not found
		return false;
	}

	protected function sortRoutes($a, $b, $uri) {
		//set vars
		$scores = [ 'a' => 0, 'b' => 0 ];
		$items = [ 'a' => (string) $a, 'b' => (string) $b ];
		//check shortcuts
		if(!$a) return -1;
		if(!$b) return 1;
		if(is_numeric($a)) return 1;
		if(is_numeric($b)) return -1;
		if($a && $a[0] == ':') return 1;
		if($b && $b[0] == ':') return -1;
		//loop through chars
		for($i=0; $i < strlen($uri); $i++) {
			//loop through items
			foreach($items as $k => $v) {
				//char matches?
				if(isset($v[$i]) && $v[$i] === $uri[$i]) {
					$scores[$k]++;
				} else {
					$items[$k] = '';
				}
			}
			//stop here?
			if(!$items['a'] && !$items['b']) {
				break;
			}
		}
		//return
		return ($scores['a'] == $scores['b']) ? 0 : ($scores['a'] > $scores['b'] ? -1 : 1);
	}

}