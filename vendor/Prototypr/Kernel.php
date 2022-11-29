<?php

namespace {

	function prototypr($opts=[]) {
		return \Prototypr\Kernel::factory($opts);
	}

}

namespace Prototypr {

	class Kernel {
	
		use ExtendTrait;

		const VERSION = '1.0.1';

		private static $_instances = [];

		private $_startMem = 0;
		private $_startTime = 0;
		private $_hasRun = false;
		private $_classCache = [];

		private $config = [];
		private $cron = [];
		private $events = [];
		private $routes = [];
		private $services = [];

		private $envs = [
			'dev',
			'qa',
			'staging',
			'prod',
		];

		private $httpMessages = [
			200 => 'Ok',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden', 
			404 => 'Not Found',
			500 => 'Internal Server Error',
		];

		public final static function factory($opts=[]) {
			//format opts?
			if(!is_array($opts)) {
				$opts = [ 'instance' => $opts ];
			}
			//set instance property?
			if(!isset($opts['instance']) || !$opts['instance']) {
				$opts['instance'] = 'default';
			}
			//create instance?
			if(!isset(self::$_instances[$opts['instance']])) {
				new \Prototypr\Kernel($opts);
			}
			//return instance
			return self::$_instances[$opts['instance']];		
		}

		public final function __construct(array $opts=[]) {
			//debug vars
			$this->_startTime = microtime(true);
			$this->_startMem = memory_get_usage();
			//base vars
			$isCli = (php_sapi_name() === 'cli');
			$incFrom = explode('/vendor/', str_replace("\\", "/", dirname(array_reverse(get_included_files())[1])))[0];
			$baseDir = (isset($opts['base_dir']) && $opts['base_dir']) ? $opts['base_dir'] : $incFrom;
			$baseUrl = (isset($opts['base_url']) && $opts['base_url']) ? $opts['base_url'] : '';
			//is cli?
			if($isCli) {
				//loop through argv
				foreach($_SERVER['argv'] as $arg) {
					//match found?
					if(strpos($arg, '-baseUrl=') === 0) {
						$baseUrl = explode('=', $arg, 2)[1];
						break;
					}
				}
				//parse url
				$parse = $baseUrl ? parse_url($baseUrl) : [];
				//set HTTPS?
				if(!isset($_SERVER['HTTPS'])) {
					$_SERVER['HTTPS'] = (isset($parse['scheme']) && $parse['scheme'] === 'https') ? 'on' : 'off';
				}
				//set HTTP_HOST?
				if(!isset($_SERVER['HTTP_HOST'])) {
					$_SERVER['HTTP_HOST'] = (isset($parse['host']) && $parse['host']) ? $parse['host'] : $_SERVER['SERVER_NAME'];
				}
				//set REQUEST_URI?
				if(!isset($_SERVER['REQUEST_URI'])) {
					$_SERVER['REQUEST_URI'] = (isset($parse['path']) && $parse['path']) ? $parse['path'] : '/';
				}
				//set SCRIPT_NAME?
				if(!isset($_SERVER['SCRIPT_NAME'])) {
					$_SERVER['SCRIPT_NAME'] = rtrim($_SERVER['REQUEST_URI'], '/') . '/index.php';
				}
			}
			//url components
			$port = (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 80;
			$ssl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? ($_SERVER['HTTPS'] !== 'off') : ($port == 443);
			$host = 'http' . ($ssl ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];
			$reqBase = explode('?', $_SERVER['REQUEST_URI'])[0];
			$scriptBase = dirname($_SERVER['SCRIPT_NAME']) ?: '/';
			//loop through opts
			foreach($opts as $k => $v) {
				if(property_exists($this, $k)) {
					$this->$k = $v;
				}
			}
			//default config opts
			$this->config = array_merge([
				//env
				'env' => null,
				'cli' => $isCli,
				'included' => $incFrom !== dirname($_SERVER['SCRIPT_FILENAME']),
				'autorun' => 'constructor',
				'webcron' => true,
				//dirs
				'base_dir' => $baseDir,
				'vendor_dirs' => array_unique([ $baseDir . '/vendor', dirname(__DIR__) ]),
				'cache_dir' => $baseDir . '/cache',
				'config_dir' => $baseDir . '/config',
				'logs_dir' => $baseDir . '/logs',
				'modules_dir' => $baseDir . '/modules',
				//url
				'ssl' => $ssl,
				'host' => $host,
				'port' => $port,
				'url' => $host . $_SERVER['REQUEST_URI'],
				'base_url' => null,
				'pathinfo' => trim(str_replace(($scriptBase === '/' ? '' : $scriptBase), '', $reqBase), '/'),
				//modules
				'modules' => [],
				'module_loading' => '',
				//other
				'instance' => 'default',
				'namespace' => null,
				'version' => null,
				'theme' => null,
				'annotations' => false,
			], $this->config);
			//cache instance
			self::$_instances[$this->config['instance']] = $this->services['kernel'] = $this;
			//format base url
			$this->config['base_url'] = $this->config['base_url'] ?: ($host . '/' . trim($scriptBase, '/'));
			$this->config['base_url'] = rtrim($this->config['base_url'], '/') . '/';
			//script included?
			if($this->config['included']) {
				$this->config['autorun'] = null;
			}
			//guess env?
			if(!$this->config['env']) {
				//set default
				$this->config['env'] = 'prod';
				//scan for env in HTTP_HOST
				$env = explode('.', $_SERVER['HTTP_HOST'])[0];
				$env = explode('-', $env)[0];
				//match found?
				if($env && in_array($env, $this->envs)) {
					$this->config['env'] = $env;
				}
			}
			//merge env config?
			if(isset($opts["config." . $this->config['env']])) {
				$this->config = array_merge($this->config, $opts["config." . $this->config['env']]);
			}
			//config file store
			$configFiles = [ 'global' => [], 'env' => [] ];
			//check config directory for matches
			foreach(glob($this->config['config_dir'] . '/*.php') as $file) {
				//look for env
				$parts = explode('.', pathinfo($file, PATHINFO_FILENAME), 2);
				$env = isset($parts[1]) ? $parts[1] : '';
				//use file?
				if($env === $this->config['env']) {
					$configFiles['env'][] = $file;
				} else if(!$env) {
					$configFiles['global'][] = $file;
				}
			}
			//loop through file types
			foreach($configFiles as $files) {
				//loop through files
				foreach($files as $file) {
					//include file
					$conf = include($file);
					//merge config?
					if($conf && is_array($conf)) {
						$this->config = array_merge($this->config, $conf);
					}
				}
			}
			//set error reporting
			error_reporting(E_ALL);
			ini_set('display_errors', 0);
			ini_set('display_startup_errors', 0);
			//exception handler
			set_exception_handler([ $this, 'logException' ]);
			//error handler
			set_error_handler($this->bind(function($type, $message, $file, $line) {
				$this->logException(new \ErrorException($message, 0, $type, $file, $line));
			}));
			//fatal error handler
			register_shutdown_function($this->bind(function() {
				//set vars
				$error = error_get_last();
				$httpCode = http_response_code();
				//log php exception?
				if($error && in_array($error['type'], [ E_ERROR, E_CORE_ERROR ])) {
					$this->logException(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
				}
				//log server error?
				if($httpCode < 100 || $httpCode >= 500) {
					//build error message
					$errMsg = 'HTTP SERVER ' . $httpCode . ': ' . $this->config('url');
					//app log error
					$this->log('errors', $errMsg);
				}
			}));
			//create default dirs
			foreach([ 'cache_dir', 'config_dir', 'logs_dir', 'modules_dir', 'vendor_dirs' ] as $k) {
				//get dir
				$dir = $this->config[$k];
				$dir = ($dir && is_array($dir)) ? $dir[0] : $dir;
				//create dir?
				if($dir && !is_dir($dir)) {
					mkdir($dir);
				}
			}
			//default file loader
			spl_autoload_register($this->bind(function($class) {
				//loop through paths
				foreach($this->config('vendor_dirs') as $path) {
					//get file path
					$path .= '/' . str_replace('\\', '/', $class) . '.php';
					//file exists?
					if(is_file($path)) {
						require_once($path);
						break;
					}
				}
			}));
			//sync composer
			$this->composer->sync();
			//check platform
			$this->platform->check();
			//loop through modules
			foreach(glob($this->config['modules_dir'] . '/*', GLOB_ONLYDIR) as $dir) {
				//get whitelist?
				if(!isset($whitelist)) {
					$whitelist = $this->config['modules'];
					$this->config['modules'] = [];
				}
				//module name
				$name = basename($dir);
				//module meta data
				$meta = array_merge([
					'path' => null,
					'platform' => null,
				], isset($whitelist[$name]) ? $whitelist[$name] : []);
				//whitelist match?
				if($whitelist && !isset($whitelist[$name]) && !in_array($name, $whitelist)) {
					continue;
				}
				//path match?
				if($meta['path'] && strpos($this->config['pathinfo'], $meta['path']) !== 0) {
					continue;
				}
				//platform match?
				if($meta['platform'] && $meta['platform'] !== $this->platform()) {
					continue;
				}
				//load now
				$this->module($name);
			}	
			//loaded event
			$this->event('app.loaded');
			//upgrade event?
			if($newV = $this->config['version']) {
				//get cached version
				$oldV = $this->cache('version');
				//new version found?
				if(!$oldV || $newV > $oldV) {
					$this->event('app.upgrade', $oldV, $newV);
					$this->cache('version', $newV);
				}
			}
			//auto run now?
			if($this->config['autorun'] === 'constructor') {
				$this->run();
			}
		}

		public function __destruct() {
			//auto-run now?
			if($this->config['autorun'] === 'destructor') {
				try {
					$this->run();
				} catch(\Exception $e) {
					$this->logException($e);
				}
			}
		}

		public function __isset($key) {
			return !!$this->service($key);
		}

		public function __get($key) {
			//service found?
			if($service = $this->service($key)) {
				return $service;
			}
			//not found
			throw new \Exception("Service $key not found");
		}

		public function __set($key, $val) {
			throw new \Exception("Cannot set properties directly on this class. Use the service method instead.");
		}

		public function isEnv($env) {
			return in_array($this->config['env'], (array) $env);
		}

		public function bind($callable, $thisArg = null) {
			//bind closure?
			if($callable instanceof \Closure) {
				$thisArg = $thisArg ?: $this;
				$callable = \Closure::bind($callable, $thisArg, $thisArg);
			}
			//return
			return $callable;
		}

		public function class($name) {
			//format name
			$name = ucfirst($name);
			//is cached?
			if(array_key_exists($name, $this->_classCache)) {
				return $this->_classCache[$name];
			}
			//is configured?
			if($class = $this->config(strtolower($name) . '_class')) {
				$this->_classCache[$name] = $class;
				return $class;
			}
			//is already a class?
			if(strpos($name, '\\') !== false && class_exists($name)) {
				$this->_classCache[$name] = $name;
				return $name;
			}
			//check namespaces
			foreach([ $this->config('namespace'), __NAMESPACE__ ] as $ns) {
				//skip namespace?
				if(!$ns) continue;
				//add namespace
				$class = $ns . '\\' . $name;
				//class exists?
				if(class_exists($class)) {
					$this->_classCache[$name] = $class;
					return $class;
				}
			}
			//not found
			$this->_classCache[$name] = null;
			return null;
		}

		public function path($path='', array $opts=[]) {
			//set vars
			$baseDir = $this->config('base_dir');
			$modulesDir = $this->config('modules_dir');
			$checkPaths = array_unique(array_merge([ $this->config('theme') ], array_keys($this->config('modules')), [ $baseDir ]));
			$checkExts = [ 'tpl' => 'tpl' ];
			//default opts
			$opts = array_merge([
				'relative' => false,
				'validate' => true,
			], $opts);
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
			if($opts['relative']) {
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
			], $opts);
			//format path
			$path = $this->path($path, [ 'validate' => false, 'relative' => true ]);
			$path = trim($path ?: $this->config('url'));
			//remove query string?
			if(!$opts['query']) {
				$path = explode('?', $path, 2)[0];
			}
			//is relative url?
			if($path[0] !== '/' && strpos($path, '://') === false) {
				//is invalid path?
				if(!preg_match('/[a-z0-9]/i', $path[0]) || preg_match('/\(|\)|\{|\}/', $path)) {
					return null;
				}
				$tmp = $path;
				$path = $this->config('base_url') . $path;
				//add timestamp?
				if($opts['time'] && strpos($tmp, '.') !== false) {
					//get file
					$file = $this->config('base_dir') . '/' . $tmp;
					//file exists?
					if(is_file($file)) {
						$path .= (strpos($path, '?') !== false ? '&' : '?') . filemtime($file);
					}
				}
			}
			//return
			return $this->clean($path, $opts['clean']);
		}

		public function config($key=null, $val='%%null%%') {
			//set vars
			$tmp = $this->config;
			$parts = $key ? explode('.', $key) : [];
			//set config item?
			if($parts && $val !== '%%null%%') {
				//set vars
				$c =& $this->config;
				//loop through parts
				foreach($parts as $k => $v) {
					if($k+1 < count($parts)) {
						$c[$v] = [];
						$c =& $c[$v];
					} else {
						$c[$v] = $val;
					}
				}
			}
			//loop through parts
			foreach($parts as $k => $v) {
				//is array?
				if(is_array($tmp) && isset($tmp[$v])) {
					$tmp = $tmp[$v];
					continue;
				}
				//is object?
				if(is_object($tmp) && isset($tmp->$v)) {
					$tmp = $tmp->$v;
					continue;
				}
				//failed
				return null;
			}
			//return
			return $tmp;
		}

		public function platform($key=null, $val=null) {
			//default key?
			if(empty($key)) {
				$key = 'loaded';
			}
			//set data?
			if(!empty($val)) {
				$this->platform->set($key, $val);
			}
			//return
			return $this->platform->get($key);
		}

		public function module($name) {
			//already loaded?
			if(!array_key_exists($name, $this->config['modules'])) {
				//get module dir
				$dir = $this->config['modules_dir'] . '/' . $name;
				//valid module?
				if(!is_dir($dir)) {
					throw new \Exception("Module $name does not exist");
				}
				//cache last loading
				$prev = $this->config['module_loading'];
				//update loading value
				$this->config['module_loading'] = $name;
				//cache module
				$this->config['modules'][$name] = null;
				//add vendor dir?
				if(is_dir($dir . '/vendor')) {
					array_unshift($this->config['vendor_dirs'], $dir . '/vendor');
				}
				//bootstrap module?
				if(is_file($dir . '/module.php')) {
					//create closure
					$moduleFn = $this->bind(function($__file) {
						require_once($__file);
					});
					//has return statement?
					if($ret = $moduleFn($dir . '/module.php')) {
						$this->config['modules'][$name] = $ret;
					}
				}
				//reset loading value
				$this->config['module_loading'] = $prev;
			}
			//return
			return $this->config['modules'][$name];
		}

		public function service($name, $obj='%%null%%') {
			//set service?
			if($obj !== '%%null%%' && !is_bool($obj)) {
				//handle service
				if($obj instanceof \Closure) {
					$this->config("{$name}_closure", $this->bind($obj));
				} else if(is_string($obj)) {
					$this->config("{$name}_class", $obj);
				} else {
					$this->services[$name] = $obj;
					$this->config("{$name}_class", get_class($obj));
				}
				//stop here
				return true;
			}
			//set vars
			$closure = $this->config("{$name}_closure");
			$opts = $this->config("{$name}_opts") ?: [];
			$shared = is_bool($obj) ? $obj : ($this->config("{$name}_shared") !== false);
			//return shared service?
			if($shared && isset($this->services[$name])) {
				return $this->services[$name];
			}
			//class exists?
			if(!$class = $this->class($name)) {
				//stop here?
				if(!$closure) {
					return null;
				}
			}
			//use annotations?
			if($class && $this->config('annotations')) {
				//reflect class
				$ref = new \ReflectionClass($class);
				//loop through props
				foreach($ref->getProperties() as $prop) {
					//set vars
					$propName = $prop->getName();
					$annotations = Meta::annotations($prop);
					//loop through annotations
					foreach($annotations as $param => $args) {
						//inject service?
						if($param === 'inject') {
							$opts[$propName] = '[' . ($args ? $args[0] : $propName) . ']';
						}
					}
				}
			}
			//resolve opts
			foreach($opts as $k => $v) {
				//is string?
				if($v && is_string($v)) {
					//replace with service?
					if($v[0] === '[' && $v[strlen($v)-1] === ']') {
						//get param
						$param = trim($v, '[]');
						//has service?
						if(!$opts[$k] = $this->service($param)) {
							//wrap helper in closure
							$opts[$k] = function() use($param) {
								$args = func_get_args();
								return $this->$param(...$args);
							};
						}
					}
					//replace with config?
					if($v[0] === '%' && $v[strlen($v)-1] === '%') {
						//get param
						$param = trim($v, '%');
						//find config
						$opts[$k] = $this->config($param);
					}
				}
			}
			//inject kernel?
			if(!$opts || !isset($opts[0])) {
				$opts['kernel'] = $this;
			}
			//create closure?
			if($closure === null) {
				//default closure
				$closure = function($opts, $class) {
					if($opts && isset($opts[0])) {
						return new $class(...$opts);
					} else if($opts) {
						return new $class($opts);
					} else {
						return new $class;
					}
				};
			}
			//init service
			$service = $closure($opts, $class);
			//cache service?
			if($shared) {
				$this->services[$name] = $service;
			}
			//return
			return $service;
		}

		public function facade($name, $obj) {
			//format name
			$name = ucfirst($name);
			//create facade class?
			if(!class_exists($name, false)) {
				eval("class $name { use " . __NAMESPACE__ . "\FacadeTrait; }");
			}
			//set instance
			$name::setInstance($obj);
			//is kernel?
			if($obj === $this) {
				$this->config('namespace', $name);
			}
			//return
			return $name;
		}

		public function event($name, $params='%%null%%', $remove=false) {
			//set array?
			if(!isset($this->events[$name])) {
				$this->events[$name] = [];
			}
			//add/remove event?
			if(is_callable($params)) {
				//generate key
				$key = md5(serialize(spl_object_hash((object) $params)));
				//add event?
				if(!$remove) {
					$this->events[$name][$key] = $this->bind($params);
				}
				//remove event?
				if($remove && isset($this->events[$name][$key])) {
					unset($this->events[$name][$key]);
				}
				//return
				return true;
			}
			//get params
			if($params !== '%%null%%') {
				$params = func_get_args();
				array_shift($params);
			} else {
				$params = [];
			}
			//execute event
			foreach($this->events[$name] as $fn) {
				//call function
				$res = call_user_func_array($fn, $params);
				//stop here?
				if($res === false) {
					$params = null;
					break;
				}
				//update params?
				if($res !== null) {
					$params[0] = $res;
				}
			}
			//return
			return $params ? $params[0] : null;
		}

		public function route($route, $callback=null, $isPrimary=false) {
			//execute route?
			if(!is_array($route) && !is_callable($callback)) {
				//format path
				$path = trim($route, '/');
				//route exists?
				if(isset($this->routes[$path])) {
					//cache route
					$route = (object) $this->routes[$path];
					//add params
					$route->path = $path;
					$route->params = is_array($callback) ? $callback : [];
					$route->isPrimary = !!$isPrimary;
					//filter route?
					if($route = $this->event('app.route', $route)) {
						//has output?
						if(!ob_get_contents()) {
							//call auth
							$auth = $route->auth ? call_user_func($route->auth) : true;
							//auth failed?
							if($auth === false) {
								http_response_code(401);
							}
							//auth passed?
							if(!ob_get_contents() && $auth !== false) {
								//cache route
								$this->config('route', $route);
								//execute
								return call_user_func($route->callback, $route->params, $route->isPrimary);
							}
						}
					}
				}
				//not found
				return false;
			}
			//format path
			$path = is_array($route) ? $route['path'] : $route;
			$path = trim($path, '/');
			//build array
			$route = array_merge([
				'callback' => null,
				'methods' => [],
				'auth' => null,
				'module' => $this->config('module_loading'),
			], is_array($route) ? $route : []);
			//bind callback
			$route['callback'] = $this->bind($callback ?: $route['callback']);
			//has valid callback?
			if(!is_callable($route['callback'])) {
				throw new \Exception("Route $path requires a valid callback");
			}
			//cache route
			$this->routes[$path] = $route;
			//return
			return true;
		}

		public function log($name, $data='%%null%%') {
			//add date prefix?
			if($data !== '%%null%%' && is_string($data)) {
				$data = '[' . date('Y-m-d H:i:s') . '] ' . $data;
			}
			//save to cache
			$res = $this->cache($this->config('logs_dir') . "/{$name}.log", $data, [ 'append' => true ]);
			//log event
			$this->event('app.log', [
				'name' => $name,
				'data' => ($data === '%%null%%') ? null : $data,
			]);
			//return
			return $res;
		}

		public function cache($path, $data='%%null%%', array $opts=[]) {
			//set vars
			$output = false;
			$closure = ($data instanceof \Closure);
			//default opts
			$opts = array_merge([
				'append' => false,
				'expiry' => 0,
			], $opts);
			//add base path?
			if(strpos($path, '/') !== 0) {
				$path = $this->config('cache_dir') . '/' . $path;
			}
			//add ext?
			if(strpos($path, '.') === false) {
				$path .= '.json';
			}
			//delete data?
			if($data === null) {
				return is_file($path) ? unlink($path) : true;
			}
			//set data?
			if($data !== '%%null%%' && !$closure) {
				//set expiry?
				if($opts['expiry']) {
					//set data
					$data = [
						'expiry' => time() + $opts['expiry'],
						'data' => $data,
					];
					//can append?
					if($opts['append']) {
						throw new \Exception("Cannot append to cache when expiry set");
					}
				}
				//encode data?
				if(!is_string($data) && !is_numeric($data)) {
					$data = json_encode($data, JSON_PRETTY_PRINT);
				}
				//append data?
				if($opts['append']) {
					$data  = ltrim($data) . "\n";
				}
				//create dir?
				if(!is_dir(dirname($path))) {
					mkdir(dirname($path), 0755, true);
				}
				//save to file
				return file_put_contents($path, $data, $opts['append'] ? LOCK_EX|FILE_APPEND : LOCK_EX);
			}
			//get file output?
			if(is_file($path)) {
				$output = file_get_contents($path);
			}
			//data found?
			if($output !== false) {
				$decode = json_decode($output, true);
				$output = is_null($decode) ? ($closure ? null : $output) : $decode;
			}
			//has expiry?
			if(is_array($output) && isset($output['expiry'])) {
				$expiry = $output['expiry'];
				$output = $output['data'];
				//has expired?
				if(time() > $expiry) {
					$output = null;
					unlink($path);
				}
			}
			//call closure?
			if($closure) {
				$output = $data($output);
			}
			//return
			return $output;
		}

		public function input($key=null, $clean='html') {
			//set vars
			$global = null;
			$globVars = [ 'GET', 'POST', 'COOKIE', 'REQUEST', 'SERVER' ];
			//ensure globals set
			$_GET; $_POST; $_COOKIE; $_REQUEST; $_SERVER;
			//inspect key?
			if(!empty($key)) {
				if(strpos($key, '.') !== false) {
					list($global, $key) = explode('.', $key, 2);
				} else if(in_array(strtoupper($key), $globVars)) {
					$global = $key;
				}
			}
			//format global
			$global = strtoupper($global ?: $_SERVER['REQUEST_METHOD']);
			//use $_POST?
			if(in_array($global, [ 'PUT', 'DELETE', 'PATCH' ])) {
				$global = 'POST';
			}
			//use $_SERVER?
			if(in_array($global, [ 'HEADER' ])) {
				$global = 'SERVER';
				$key = $key ? 'HTTP_' . strtoupper($key) : null;
			}
			//global is key?
			if($key && $global === strtoupper($key)) {
				$key = null;
			}
			//format global
			$global = '_' . $global;
			//has global?
			if(!isset($GLOBALS[$global])) {
				return null;
			}
			//has key?
			if(empty($key)) {
				$value = $GLOBALS[$global];
			} else {
				$value = isset($GLOBALS[$global][$key]) ? $GLOBALS[$global][$key] : null;
			}
			//return
			return $this->clean($value, $clean);
		}

		public function clean($value, $context='html') {
			//is raw?
			if(!$context || $context === 'raw') {
				return $value;
			}
			//is callable?
			if(is_callable($context)) {
				return call_user_func($context, $value);
			}
			//is recursive?
			if(is_array($value) || is_object($value)) {
				//loop through array
				foreach($value as $k => $v) {
					//clean value
					$v = $this->clean($v, $context);
					//is object?
					if(is_object($value)) {
						$value->$k = $v;
					} else {
						$value[$k] = $v;
					}
				}
				//return
				return $value;
			}
			//is string?
			if(is_string($value) || is_numeric($value)) {
				//is html?
				if($context === 'html') {
					return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8', false);
				}
				//is attribute?
				if($context === 'attr') {
					return preg_replace_callback('/[^a-z0-9,\.\-_]/iSu', function($matches) {
						$chr = $matches[0];
						$ord = ord($chr);
						if(($ord <= 0x1f && $chr != "\t" && $chr != "\n" && $chr != "\r") || ($ord >= 0x7f && $ord <= 0x9f)) {
							return '&#xFFFD;'; //replacement for undefined characters in html
						}
						$ord = hexdec(bin2hex($chr));
						if($ord > 255) {
							return sprintf('&#x%04X;', $ord);
						} else {
							return sprintf('&#x%02X;', $ord);
						}
					}, $value);
				}
				//is js?
				if($context === 'js') {
					return preg_replace_callback('/[^a-z0-9,\._]/iSu', function($matches) {
						$chr = $matches[0];
						if(strlen($chr) == 1) {
							return sprintf('\\x%02X', ord($chr));
						}
						$hex = strtoupper(bin2hex($chr));
						if(strlen($hex) <= 4) {
							return sprintf('\\u%04s', $hex);
						} else {
							return sprintf('\\u%04s\\u%04s', substr($hex, 0, 4), substr($hex, 4, 4));
						}
					}, $value);		
				}		
			}
			//unprocessed
			return $value;
		}

		public function tpl($name, array $data=[], $code=null) {
			//set code?
			if($code > 0) {
				http_response_code($code);
			}
			//load view
			$this->view->tpl($name, $data, true);
		}

		public function json($data, $code=null) {
			//set vars
			$checkMsg = false;
			//guess code?
			if(!$code && is_array($data) && isset($data['code'])) {
				$code = (int) $data['code'];
				$checkMsg = true;
			}
			//has code?
			if($code > 0) {
				//set response code
				http_response_code($code);
				//set response message?
				if($checkMsg && isset($this->httpMessages[$code])) {
					if(!isset($data['message']) || !$data['message']) {
						$data['message'] = $this->httpMessages[$code];
						ksort($data);
					}
				}
			}
			//filter output
			$data = $this->event('app.json', $data);
			//set content-type?
			if(!ob_get_contents()) {
				header("Content-Type: application/json");
			}
			//display
			echo json_encode($data, JSON_PRETTY_PRINT);
		}

		public function http($url, array $opts=[], $redirects=0) {
			//set vars
			$status = 0;
			$response = '';
			//heloer: parse headers
			$parseHeaders = function($headers) {
				//convert to array?
				if(!is_array($headers)) {
					$headers = explode("\r\n", $headers);
				}
				//loop through headers
				foreach($headers as $k => $v) {
					//delete key
					unset($headers[$k]);
					//get key?
					if(is_numeric($k)) {
						list($k, $v) = array_map('trim', explode(':', $v, 2));
					}
					//add key
					$headers[strtolower($k)] = $v;
				
				}
				//return
				return $headers;
			};
			//default opts
			$opts = array_merge([
				'method' => '',
				'headers' => [],
				'query' => [],
				'body' => '',
				'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.81 Safari/537.36',
				'protocol' => '1.0',
				'timeout' => 3,
				'blocking' => 1,
				'max_redirects' => 3,
				'response_headers' => false,
			], $opts);
			//format body?
			if(is_array($opts['body'])) {
				$opts['body'] = http_build_query($opts['body']);
			}
			//parse headers
			$opts['headers'] = $parseHeaders($opts['headers']);
			//set content type?
			if($opts['body'] && !isset($opts['headers']['content-type'])) {
				$opts['headers']['content-type'] = "application/x-www-form-urlencoded";
			}
			//set content length?
			if($opts['body'] && !isset($opts['headers']['content-length'])) {
				$opts['headers']['content-length'] = strlen($opts['body']);
			}
			//set user agent?
			if($opts['user_agent'] && !isset($opts['headers']['user-agent'])) {
				$opts['headers']['user-agent'] = $opts['user_agent'];
			}
			//cache url
			$opts['url'] = $url;
			//format method
			$opts['method'] = strtoupper(trim($opts['method'])) ?: 'GET';
			//Event: http.request
			$opts = $this->event('http.request', $opts);
			//add query string?
			if($opts['query']) {
				$opts['url'] .= (strpos($opts['url'], '?') === false) ? '?' : '&';
				$opts['url'] .= is_array($opts['query']) ? http_build_query($opts['query']) : $opts['query'];
			}
			//parse url
			$parse = array_merge([
				'scheme' => '',
				'port' => 0,
				'host' => '',
				'path' => '/',
				'query' => '',
			], parse_url($opts['url']));
			//is ssl?
			if(in_array($parse['scheme'], [ 'ssl', 'https' ])) {
				$parse['scheme'] = 'ssl';
				$parse['port'] = $parse['port'] ?: 443;
			} else if(in_array($parse['scheme'], [ 'tcp', 'http' ])) {
				$parse['scheme'] = 'tcp';
				$parse['port'] = $parse['port'] ?: 80;
			}
			//successful connection?
			if($fp = fsockopen($parse['scheme'] . '://' . $parse['host'], $parse['port'], $errno, $errmsg, $opts['timeout'])) {
				//set stream options
				stream_set_timeout($fp, $opts['timeout']);
				stream_set_blocking($fp, $opts['blocking']);
				//open request
				$request  = $opts['method'] . " " . ($parse['path'] ?: '/') . ($parse['query'] ? '?' . $parse['query'] : '') . " HTTP/" . $opts['protocol'] . "\r\n";
				$request .= "Host: " . $parse['host'] . "\r\n";
				//add custom headers
				foreach($opts['headers'] as $k => $v) {
					$request .= ucfirst($k) . ": " . $v . "\r\n";
				}
				//close request
				$request .= "Connection: Close\r\n\r\n";
				$request .= $opts['body'];
				//send request
				fputs($fp, $request, strlen($request));
				//read buffer
				while(!feof($fp)) { 
					//get next line
					$line = fgets($fp, 4096);
					//get meta data
					$info = stream_get_meta_data($fp);
					//has timed out?
					if($line === false || $info['timed_out']) {
						break;
					}
					//add to response
					$response .= $line;
				}
				//close
				fclose($fp);
			}
			//parse response?
			if($response) {
				//split headers & body
				list($headers, $body) = explode("\r\n\r\n", $response, 2);
				//parse headers
				$headers = explode("\r\n", $headers);
				//get status code?
				if(preg_match('{HTTP\/\S*\s(\d{3})}', $headers[0], $match)) {
					$status = (int) $match[1];
					unset($headers[0]);
				}
				//parse headers
				$headers = $parseHeaders($headers);
				//auto redirect?
				if($status == 301 || $status == 302) {
					//can follow?
					if(isset($headers['location'])) {
						//within max redirects?
						if($redirects < $opts['max_redirects']) {
							return $this->http($headers['location'], $opts, ++$redirects);
						}
					}
				}
				//try to parse body
				$decode = json_decode($body, true);
				$body = is_null($decode) ? $body : $decode;
				//wrap response?
				if($opts['response_headers']) {
					$response = [ 'status' => $status, 'headers' => $headers, 'body' => $body ];
				} else {
					$response = $body;
				}
				//log response error?
				if($status < 100 || $status >= 400) {
					$this->log('errors', 'HTTP REQUEST ' . $status . ': ' . $opts['method'] . ' ' . $opts['url']);
				}
			}
			//return
			return $response;
		}

		public function mail($to, $subject, $body, array $opts=[]) {
			//set vars
			$headers = '';
			//set defaults
			$opts = array_merge([
				'subject' => trim($subject),
				'body' => trim($body),
				'to' => trim($to),
				'to_name' => '',
				'from' => $this->config('mail_from') ?: 'no-reply@' . $this->input('SERVER.HTTP_HOST'),
				'from_name' => $this->config('mail_name') ?: $this->config('name'),
				'headers' => [],
				'html' => null,
			], $opts);
			//is html?
			if($opts['html'] === null) {
				$opts['html'] = strip_tags($opts['body']) !== $opts['body'];
			}
			//add lines breaks?
			if($opts['html'] && strip_tags($opts['body']) === strip_tags($opts['body'], '<p><br><div><table>')) {
				$opts['body'] = str_replace("\n", "\n<br>\n", $opts['body']);
			}
			//resolve placeholders
			foreach($opts as $k => $v) {
				if(is_scalar($v)) {
					$opts['subject'] = str_replace('%' . $k . '%', $v, $opts['subject']);
					$opts['body'] = str_replace('%' . $k . '%', $v, $opts['body']);
				}
			}
			//default headers
			$opts['headers'] = array_merge([
				'From' => $opts['from_name'] ? ($opts['from_name'] . ' <' . $opts['from'] . '>') : $opts['from'],
				'Reply-To' => $opts['from'],
				'Content-Type' => $opts['html'] ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8',
				'MIME-Version' => $opts['html'] ? '1.0' : '',
			], $opts['headers']);
			//mail event
			$opts = $this->event('app.mail', $opts);
			//return now?
			if(!is_array($opts)) {
				return !!$opts;
			}
			//convert headers to string
			foreach($opts['headers'] as $k => $v) {
				if(!empty($v)) {
					$headers .= ucfirst($k) . ': ' . $v . "\r\n";
				}
			}
			//valid from mail?
			if(!filter_var($opts['from'], FILTER_VALIDATE_EMAIL)) {
				throw new \Exception("From email not set");
			}
			//use safe mode?
			if(ini_get('safe_mode')) {
				return mail($opts['to'], $opts['subject'], $opts['body'], $headers);
			} else {
				return mail($opts['to'], $opts['subject'], $opts['body'], $headers, '-f' . $opts['from']);
			}
		}

		public function form($name, $method='post', $action='') {
			//class found?
			if(!$class = $this->class('form')) {
				return null;
			}
			//format opts
			if(is_array($method)) {
				$opts = $method;
			} else {
				$opts = [
					'attr' => [
						'method' => $method,
						'action' => $action,
					],
				];
			}
			//add kernel
			$opts['kernel'] = $this;
			//create form
			return $class::factory($name, $opts);
		}

		public function model($name, $data=[], $find=true) {
			//select method
			$method = ($find && $data) ? 'load' : 'create';
			//return model
			return $this->orm->$method($name, $data ?: []);
		}

		public function schedule($name, $fn='%%null%%', $interval=3600, $reset=false) {
			//set vars
			$next = null;
			$update = false;
			$jobs = $this->cache('cron') ?: [];
			//loop though jobs
			foreach($jobs as $k => $v) {
				if($v['name'] === $name) {
					$next = $k;
					break;
				}
			}
			//delete job?
			if($next && ($reset || $fn === null)) {
				unset($jobs[$next], $this->cron[$next]);
				$update = true;
				$next = null;
			}
			//add job?
			if(!$next && $fn && $fn !== '%%null%%') {
				//get next run time
				$next = time() + $interval;
				//add to array
				while(true) {
					if(isset($jobs[$next])) {
						$next++;
					} else {
						$jobs[$next] = [ 'name' => $name, 'interval' => $interval ];
						$update = true;
						break;
					}
				}
			}
			//update cache?
			if($update) {
				ksort($jobs);
				$this->cache('cron', $jobs);
			}
			//save callback?
			if($fn && $fn !== '%%null%%') {
				$this->cron[$next] = [ 'name' => $name, 'interval' => $interval, 'fn' => $this->bind($fn) ];
				ksort($this->cron);
			}
			//stop here?
			if(!$next || !isset($this->cron[$next])) {
				return null;
			}
			//get function
			$fn = $this->cron[$next]['fn'];
			//return function
			return function() use($fn) {
				return call_user_func($fn);
			};
		}

		public function cron($job=null) {
			//set vars
			$limit = 300;
			$jobs = $this->cache('cron') ?: [];
			$isRunning = $this->cache('cron-running');
			$next = $this->cron ? array_keys($this->cron)[0] : 0;
			//has cron?
			if(!$this->cron) {
				return;
			}
			//all jobs?
			if(empty($job)) {
				//reset cron?
				if($isRunning && $isRunning < (time() - $limit)) {
					$isRunning = null;
					$this->cache('cron-running', null);
				}
				//create web cron?
				if(!isset($_GET['cron']) && $this->config('webcron') && !$this->config('cli')) {
					//call now?
					if($next <= time() && !$isRunning) {
						$url = $this->config('base_url') . '?cron=' . time();
						$this->http($url, [ 'blocking' => false ]);
					}
					//stop
					return;
				}
				//execute cron?
				if($isRunning || $next > time()) {
					return;
				}
			}
			//let run
			set_time_limit($limit);
			ignore_user_abort(true);
			//lock cron
			$this->cache('cron-running', time());
			//loop through jobs
			foreach($this->cron as $time => $meta) {
				//valid job?
				if($job && $job !== $meta['name']) {
					continue;
				}
				//has callback?
				if(!isset($meta['fn']) || !$meta['fn']) {
					continue;
				}
				//valid time?
				if(!$job && $time > time()) {
					break;
				}
				//call function
				try {
					call_user_func($meta['fn']);
				} catch (\Exception $e) {
					$this->logException($e);
				}
				//reset job?
				if(isset($jobs[$time])) {
					$this->schedule($meta['name'], $meta['fn'], $meta['interval'], true);
				}
			}
			//release cron
			$this->cache('cron-running', null);
		}

		public function run() {
			//has run?
			if($this->_hasRun) {
				return;
			}
			//update flag
			$this->_hasRun = true;
			//set vars
			$code = 0;
			$fallback = 404;
			$matched = false;
			$pathInfo = (string) $this->config('pathinfo');
			$webCron = $this->config('webcron') && !$this->config('cli');
			$cliCron = isset($_SERVER['argv']) && in_array('-cron', $_SERVER['argv']);
			//init event
			$this->event('app.init');
			//run cron?
			if($cliCron || $webCron) {
				//execute
				$this->cron();
				//stop here?
				if($cliCron || isset($_GET['cron'])) {
					return;
				}
			}
			//sort keys by similarity to $pathInfo
			uksort($this->routes, function($a, $b) use($pathInfo) {
				//set vars
				$scores = [ 'a' => 0, 'b' => 0 ];
				$items = [ 'a' => (string) $a, 'b' => (string) $b ];
				//check shortcuts
				if($a == '404') return 1;
				if($b == '404') return -1;
				if(!$a && !$pathInfo) return -1;
				if(!$b && !$pathInfo) return 1;
				//loop through chars
				for($i=0; $i < strlen($pathInfo); $i++) {
					//loop through items
					foreach($items as $k => $v) {
						//char matches?
						if(isset($v[$i]) && $v[$i] === $pathInfo[$i]) {
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
			});
			//start buffer
			ob_start();
			//loop through routes
			foreach($this->routes as $path => $route) {
				//set vars
				$target = $path;
				//is fallback path?
				if(preg_match('/(^|\/)' . $fallback . '(\/|$)/', $target, $m)) {
					$m[0] = str_replace($fallback, '(.*)', $m[0]);
					$target = preg_replace('/(^|\/)' . $fallback . '(\/|$)/', $m[0], $target);
				}
				//valid method?
				if($route['methods'] && !in_array($_SERVER['REQUEST_METHOD'], $route['methods'])) {
					continue;
				}
				//valid route?
				if(!preg_match('/^' . str_replace([ '/', '\\\\' ], [ '\/', '\\' ], $target) . '$/', $pathInfo, $params)) {
					continue;
				}
				//format params
				array_shift($params);
				$params = array_map(function($v) { return str_replace('/', '', $v); }, $params);
				//match found?
				if($this->route($path, $params, true) !== false) {
					$matched = true;
					break;
				}
				//is unauthorized?
				if(http_response_code() == 401) {
					$fallback = 401;
				}
			}
			//no route matched?
			if($matched === false) {
				//get response code
				if(http_response_code() == 200) {
					$code = 404;
				} else {
					$code = http_response_code();
				}
				//set default output?
				if(!ob_get_contents()) {
					//use template?
					if($this->path($code . '.tpl')) {
						$this->tpl($code);
					} else if(isset($this->httpMessages[$code])) {
						echo '<h1>' . $this->httpMessages[$code] . '</h1>';
					} else {
						echo '<h1>Internal server error: ' . $code . '</h1>';
					}
				}
			}
			//get final output
			$output = trim(ob_get_clean());
			//add debug bar?
			if($this->isEnv('dev')) {
				$output = str_replace('</body>', $this->debug(true) . "\n" . '</body>', $output);
			}
			//set response code?
			if($code > 0) {
				http_response_code($code);
			}
			//filter output?
			if($output = $this->event('app.output', $output)) {
				echo $output;
			}
			//shutdown event
			$this->event('app.shutdown');
		}

		public function debug($asHtml = false) {
			//set vars
			$data = [
				'time' => number_format(microtime(true) - $this->_startTime, 5) . 's',
				'mem' => number_format((memory_get_usage() - $this->_startMem) / 1024, 0) . 'kb',
				'mem_peak' => number_format(memory_get_peak_usage() / 1024, 0) . 'kb',
				'queries' => 0,
				'queries_log' => [],
			];
			//check queries?
			if(isset($this->services['db']) && !($this->services['db'] instanceOf \Closure)) {
				//count queries
				$data['queries'] = $this->db->num_queries;
				//get query log
				$data['queries_log'] = array_map(function($item) {
					return preg_replace("/\s+/", " ", $item);
				}, $this->db->queries);
			}
			//stop here?
			if(!$asHtml) {
				return $data;
			}
			//create html
			$html  = '<div id="debug-bar" style="width:100%; font-size:12px; text-align:left; padding:10px; margin-top:20px; background:#eee; position:fixed; bottom:0;">' . "\n";
			$html .= '<div><b>Debug:</b> Time: ' . $data['time'] . ' | Mem: ' . $data['mem'] . ' | Peak: ' . $data['mem_peak'] . ' | Queries: ' . $data['queries'] . '</div>' . "\n";
			//show db queries?
			if($data['queries_log']) {
				$html .= '<ol style="margin:10px 0 0 0; padding-left:15px;">' . "\n";
				foreach($data['queries_log'] as $q) {
					$html .= '<li style="margin-top:3px;">' . $q . '</li>' . "\n";
				}
				$html .= '</ol>' . "\n";
			}
			$html .= '</div>' . "\n";
			//return
			return $html;
		}

		public function backtrace() {
			//set vars
			$output = [];
			$backtrace = debug_backtrace();
			//loop through backtrace
			foreach($backtrace as $index => $item) {
				//skip index?
				if(!$index) continue;
				//set function
				$fn = $item['function'] . '()';
				$file = isset($item['file']) ? str_replace($this->config('base_dir') . '/', '', $item['file']) : '';
				//has class?
				if(isset($item['type']) && $item['type']) {
					$fn = $item['class'] . $item['type'] . $fn;
				}
				//add to output
				$output[] = $fn . ($file ? ' in ' . $file . ' @ ' . $item['line'] : '');
			}
			//return
			return $output;
		}

		public function logException($e, $display=true) {
			//set vars
			$type = 'ERROR';
			$severity = method_exists($e, 'getSeverity') ? $e->getSeverity() : 0;
			$consts = array_flip(array_slice(get_defined_constants(true)['Core'], 0, 15, true));
			//get severity
			foreach($consts as $code => $name) {
				if($severity && $severity == $code) {
					$type = str_replace('E_', '', $name);
				}
			}
			//error parts
			$error = [
				'date' => date('Y-m-d H:i:s'),
				'type' => $type,
				'severity' => $severity,
				'message' => $e->getMessage(),
				'line' => $e->getLine(),
				'file' => $e->getFile(),
				'trace' => $e->getTraceAsString(),
				'debug' => isset($e->debug) ? $e->debug : [],
				'display' => $display,
			];
			//custom error handling?
			if($error = $this->event('app.error', $error)) {
				//build error message
				$errMsg = 'PHP ' . $error['type'] . ':  ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'];
				//php error log
				error_log($errMsg);
				//app log error
				$this->log('errors', $errMsg);
				//display error?
				if($error['display']) {
					//is dev?
					if($this->isEnv('dev')) {
						echo '<div class="err" style="margin:1em 0; padding: 0.5em; border:1px red solid;">';
						echo $errMsg . ' <a href="javascript:void(0);" onclick="this.nextSibling.style.display=\'block\';">[show trace]</a>';
						echo '<div style="display:none; padding-top:15px;">';
						foreach($error['debug'] as $k => $v) {
							echo '<b>' . $k . '</b>: ' . var_export($v, true);
							echo '<br><br>';
						}
						echo str_replace("\n", "<br>", $error['trace']);
						echo '</div>';
						echo '</div>';
						echo "\n";
					} else if(preg_match('/ERROR|PARSE/i', $error['type'])) {
						if($this->path('500')) {
							$this->tpl('500');
						} else {
							echo '<h1>Internal Server Error</h1>';
						}
					}
				}
			}
		}

	}

	trait ConstructTrait {

		protected $kernel;

		public function __construct(array $opts=[], $merge=true) {
			//set opts
			foreach($opts as $k => $v) {
				//property exists?
				if(property_exists($this, $k)) {
					//is array?
					if($merge && $this->$k === (array) $this->$k) {
						$this->$k = array_merge($this->$k, $v);
					} else {
						$this->$k = $v;
					}
				}
			}
			//set kernel?
			if(!$this->kernel) {
				$this->kernel = prototypr();
			}
			//hook
			$this->onConstruct($opts);
		}

		protected function onConstruct(array $opts) {
			return;
		}

	}

	trait ExtendTrait {

		protected $__calls = [];

		public function __call($method, array $args) {
			//target method exists?
			if(method_exists($this, '__target')) {
				//get target
				$target = $this->__target();
				//can call method?
				if(method_exists($target, $method)) {
					return $target->$method(...$args);
				}
			}
			//extension found?
			if(isset($this->__calls[$method])) {
				return $this->__calls[$method](...$args);
			}
			//not found
			throw new \Exception("Method $method not found");
		}

		public final function extend($method, $callable = null) {
			//set vars
			$ext = [];
			$target = method_exists($this, '__target') ? $this->__target() : $this;
			//sync class?
			if(strpos($method, '\\') > 0) {
				//class data
				$class = $method;
				$opts = is_array($callable) ? $callable : [];
				//default opts
				$opts = array_merge([
					'magic' => false,
					'private' => false,
					'inherited' => false,
					'existing' => false,
					'whitelist' => [],
				], $opts);
				//reflection class
				$ref = new \ReflectionClass($class);
				//loop through methods
				foreach($ref->getMethods() as $rm) {
					//get name
					$method = $rm->getName();
					//skip non-whitelist method?
					if($opts['whitelist'] && !in_array($method, $opts['whitelist'])) {
						continue;
					}
					//skip magic method?
					if(!$opts['magic'] && strpos($method, '__') === 0) {
						continue;
					}
					//skip private method?
					if(!$opts['private'] && !$rm->isPublic()) {
						continue;
					}
					//skip inherited method?
					if(!$opts['inherited'] && $class !== $rm->getDeclaringClass()->name) {
						continue;
					}
					//skip existing method?
					if(!$opts['existing'] && method_exists($target, $method)) {
						continue;
					}
					//add to array
					$ext[$method] = [ $class, $method ];
				}
			} else {
				$ext[$method] = $callable;
			}
			//loop through extensions
			foreach($ext as $method => $callable) {
				//can add callable?
				if($callable = Meta::closure($callable, $target)) {
					$this->__calls[$method] = $callable;
				}
			}
		}

	}

	trait FacadeTrait {

		private static $instance;

		public final static function setInstance($instance) {
			self::$instance = $instance;
		}

		public final static function __callStatic($method, $args) {
			return self::$instance->$method(...$args);
		}

	}

}