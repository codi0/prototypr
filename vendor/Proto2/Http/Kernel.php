<?php

namespace Proto2\Http;

//PSR-15 compatible
class Kernel {

	protected $router;

	protected $middleware = [
		'before' => [],
		'route' => [],
		'after' => [],
		'dispatch' => [],
	];

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
	}

	public function __invoke($request) {
		return $this->handle($request);
	}

	public function middleware($callable, $stack='after', $prepend=false) {
		//does stack exist?
		if(!isset($this->middleware[$stack])) {
			throw new \Exception("Middleware stack does not exist: " . $stack);
		}
		//prepend?
		if($prepend) {
			array_unshift($this->middleware[$stack], $callable);
		} else {
			$this->middleware[$stack][] = $callable;
		}
		//chain it
		return $this;
	}

	public function handle($request) {
		//set vars
		$item = null;
		$response = null;
		//loop through middleware
		foreach($this->middleware as $key => $val) {
			//item found?
			if($item = current($this->middleware[$key])) {
				next($this->middleware[$key]);
				break;
			}
		}
		//has item?
		if(!empty($item)) {
			//is callable?
			if(is_callable($item)) {
				$response = call_user_func($item, $request, $this);
			} else {
				//create object?
				if(is_string($item)) {
					$item = new $item;
				}
				//has process method?
				if(method_exists($item, 'process')) {
					$response = $item->process($request, $this);
				} else if(method_exists($item, 'handle')) {
					$response = $item->handle($request);
				} else {
					throw new \Exception("Invalid middleware callable");
				}
			}
			//valid response?
			if(!is_object($response)) {
				throw new \Exception("HTTP response not returned from middleware");
			}
		}
		//return
		return $response ?: new Response;
	}

	public function createRequest($message = null) {
		//use message?
		if($message) {
			return ServerRequest::createFromRaw($message);
		}
		//from globals
		return ServerRequest::createFromGlobals();
	}

	public function run($request = null, $callback = null) {
		//has request?
		if(!is_object($request)) {
			$request = $this->createRequest($request);
		}
		//setup defaults
		$this->defaultMiddleware($request, $callback);
		//get response
		return $this->handle($request);
	}

	protected function defaultMiddleware($request, $callback) {
		//add route matcher?
		if($this->router && !$this->middleware['route']) {
			//set route middleware
			$this->middleware(function($request, $next) {
				$route = $this->router->match($request);
				$request = $request->withAttribute('route', $route);
				return $next($request);
			}, 'route');
		}
		//add dispatcher?
		if(($this->router || $callback) && !$this->middleware['dispatch']) {
			//add dispatch middleware
			$this->middleware($callback ?: function($request, $next) {
				return $this->router->dispatch($request, $next($request));
			}, 'dispatch');	
		}
	}

}