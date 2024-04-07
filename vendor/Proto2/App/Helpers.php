<?php

namespace Proto2\App;

class Helpers {

	protected $kernel;
	protected $__calls = [];

	public function __construct($kernel) {
		$this->kernel = $kernel;
	}

	public function __call($method, array $args) {
		//method exists?
		if(!isset($this->__calls[$method])) {
			throw new \Exception("Method $method not found");
		}
		//return
		return call_user_func_array($this->__calls[$method], $args);
	}

	public function __extend($method, $callable, $ctx=true) {
		//bind closure?
		if($ctx && ($callable instanceof \Closure)) {
			$ctx = is_object($ctx) ? $ctx : $this;
			$callable = \Closure::bind($callable, $ctx, $ctx);
		}
		//set property
		$this->__calls[$method] = $callable;
		//chain it
		return $this;
	}

	public function caller($key=null) {
		//exception trace
		$ex = new \Exception;
		$trace = $ex->getTrace();
		$arr = isset($trace[1]) ? $trace[1] : [];
		//return
		return $key ? (isset($arr[$key]) ? $arr[$key] : null) : $arr;
	}

	public function path($path='', array $opts=[]) {
		//default opts
		$opts = array_merge([
			'module' => '',
			'caller' => '',
			'relative' => false,
			'validate' => true,
		], $opts);
		//set vars
		$path = $path ?: '';
		$config = $this->kernel->config;
		$baseDir = $config->get('paths.base');
		$modulesDir = $config->get('paths.modules');
		$moduleNames = array_keys($config->get('modules'));
		//check calling module?
		if(empty($opts['module'])) {
			//get caller
			$caller = $opts['caller'] ?: $this->caller('file');
			//loop through modules
			foreach($moduleNames as $m) {
				//match found?
				if(strpos($caller, $modulesDir . '/' . $m . '/') === 0) {
					$opts['module'] = $m;
					break;
				}
			}
		}
		//set module hint?
		if($opts['module']) {
			array_unshift($moduleNames, $opts['module']);
			$moduleNames = array_unique($moduleNames);
		}
		//merge values to check
		$checkExts = [ 'tpl' => 'tpl' ];
		$checkPaths = array_unique(array_merge([ $config->get('theme') ], $moduleNames, [ $baseDir ]));
		//is url?
		if(strpos($path, '://') !== false) {
			return $path;
		}
		//virtual path?
		if($path && $path[0] !== '/') {
			//get file ext
			$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			$ext = isset($checkExts[$ext]) ? $checkExts[$ext] : '';
			//loop through paths
			foreach($checkPaths as $base) {
				//is empty?
				if(empty($base)) {
					continue;
				}
				//add prefix?
				if(strpos($base, '/') === false) {
					$base = $modulesDir . '/' . $base;
				}
				//check base + ext?
				if($ext && file_exists($base . '/' . $ext . '/' . $path)) {
					$path = $base . '/' . $ext . '/' . $path;
					break;
				}
				//check base?
				if(file_exists($base . '/' . $path)) {
					$path = $base . '/' . $path;
					break;
				}
			}
		}
		//is empty?
		if(empty($path)) {
			$path = $baseDir;
		}
		//is valid?
		if($opts['validate'] && !file_exists($path)) {
			return null;
		}
		//is relative?
		if($opts['relative'] && $path !== '/') {
			$path = str_replace($baseDir, '', $path);
			$path = ltrim($path, '/');
		}
		//return
		return $path;
	}

	public function url($path='', array $opts=[]) {
		//default opts
		$opts = array_merge([
			'time' => false,
			'query' => true,
			'clean' => '',
			'module' => '',
		], $opts);
		//create path
		$path = $this->path($path, [
			'module' => $opts['module'],
			'caller' => $this->caller('file'),
			'validate' => false,
			'relative' => true,
		]);
		//misc vars
		$query = [];
		$config = $this->kernel->config;
		$path = trim($path ?: $config->get('urls.current'));
		//has query string?
		if(strpos($path, '?') !== false) {
			list($path, $query) = explode('?', $path, 2);
			parse_str($query, $query);
		}
		//has query params?
		if(is_array($opts['query'])) {
			//process query params
			foreach($opts['query'] as $k => $v) {
				//add or remove?
				if(is_string($v)) {
					$query[$k] = $v;
				} else if(isset($query[$k])) {
					unset($query[$k]);
				}
			}
		} else if($opts['query'] === false) {
			$query = [];
		}
		//add query string?
		if(!empty($query)) {
			$path .= '?' . http_build_query($query);
		}
		//is relative url?
		if($path[0] !== '/' && strpos($path, '://') === false) {
			//is invalid path?
			if(!preg_match('/[a-z0-9]/i', $path[0]) || preg_match('/\(|\)|\{|\}/', $path)) {
				return null;
			}
			$tmp = $path;
			$path = $config->get('urls.base') . '/' . trim($path, '/');
			//add timestamp?
			if($opts['time'] && strpos($tmp, '.') !== false) {
				//get file
				$file = $config->get('paths.base') . '/' . $tmp;
				//file exists?
				if(is_file($file)) {
					$path .= (strpos($path, '?') !== false ? '&' : '?') . filemtime($file);
				}
			}
		}
		//return
		return $path;
	}

	public function facade($name, $obj = null) {
		//set vars
		$name = ucfirst($name);
		$obj = $obj ?: $this;
		//create facade class
		$cls = 'class ' . $name . ' {
			private static $instance;
			public static function setInstance($instance) {
				self::$instance = $instance;
			}
			public static function __callStatic($method, $args) {
				return self::$instance->$method(...$args);
			}
			public static function get($key) {
				return isset(self::$instance->$key) ? self::$instance->$key : null;
			}
		}';
		//eval class?
		if(!class_exists($name, false)) {
			eval($cls);
		}
		//set instance
		$name::setInstance($obj);
		//return
		return $name;
	}

	public function json(array $data, $header=true) {
		//set header?
		if($header) {
			header('Content-Type: application/json');
		}
		//return
		return json_encode($data);
	}

	public function http($uri, array $params=[]) {
		//kernel objects
		$httpClient = $this->kernel->httpClient;
		//send request
		return $httpClient->send($uri, $params);
	}

	public function form($name, $method='post', $action='') {
		//kernel objects
		$registry = $this->kernel->registry;
		//create object
		return $registry->create('htmlForm', [
			'opts' => [
				'name' => $name,
				'attr' => [
					'name' => $name,
					'method' => strtoupper($method),
					'action' => $action,
				],
			],
		]);
	}

	public function table($name) {
		//kernel objects
		$registry = $this->kernel->registry;
		//create object
		return $registry->create('htmlTable', [
			'opts' => [
				'name' => $name,
			],
		]);
	}

}