<?php

namespace {

	require_once('Utils.php');

	function prototypr($opts=[]) {
		return \Prototypr\Kernel::factory($opts);
	}

}

namespace Prototypr {

	class Kernel {
	
		use ExtendTrait;

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

		private $httpMessages = [
			200 => 'Ok',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden', 
			404 => 'Not Found',
			500 => 'Internal Server Error',
		];

		public final static function factory($opts=[]) {
			//cache last instance
			static $last = 'default';
			//format opts?
			if(!is_array($opts)) {
				$opts = [ 'instance' => $opts ];
			}
			//set instance property?
			if(!isset($opts['instance']) || !$opts['instance']) {
				$opts['instance'] = $last;
			}
			//update last instance
			$last = $opts['instance'];
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
			//helper: format dir
			$formatDir = function($dir) {
				return str_replace("\\", "/", $dir);
			};
			//check for constants
			foreach([ 'env', 'debug', 'base_dir' ] as $k) {
				//get const name
				$const = strtoupper(__NAMESPACE__ . '_' . $k);
				$val = defined($const) ? constant($const) : null;
				//set config key?
				if(!isset($opts['config'])) {
					$opts['config'] = [];
				}
				//override option?
				if($val !== null && $val !== '') {
					$opts['config'][$k] = $val;
				}
			}
			//base vars
            $isCli = php_sapi_name() === 'cli' || defined('STDIN');
			$scriptDir = $formatDir(dirname($_SERVER['SCRIPT_FILENAME']));
			$incFrom = explode('/vendor/', $formatDir(dirname(array_reverse(get_included_files())[1])))[0];
			$baseUrl = isset($opts['config']['base_url']) ? $opts['config']['base_url'] : '';
			$baseDir = isset($opts['config']['base_dir']) ? $opts['config']['base_dir'] : '';
			//format base dir
			$baseDir = $opts['config']['base_dir'] = $formatDir($baseDir ?: $incFrom);
			//is cli?
			if($isCli) {
				//set vars
				$route = '';
				$parseUrl = [];
				//loop through argv
				foreach($_SERVER['argv'] as $arg) {
					//trim arg
					$arg = ltrim($arg, '-');
					//has base url?
					if(strpos($arg, 'baseUrl=') === 0) {
						$baseUrl = trim(explode('=', $arg, 2)[1], '/');
						continue;
					}
					//has route?
					if(strpos($arg, 'route=') === 0) {
						$route = trim(explode('=', $arg, 2)[1], '/');
						continue;
					}
				}
				//can parse?
				if(!empty($baseUrl)) {
					$parseUrl = parse_url($baseUrl) ?: [];
				}
				//set HTTPS?
				if(!isset($_SERVER['HTTPS'])) {
					$_SERVER['HTTPS'] = (isset($parseUrl['scheme']) && $parseUrl['scheme'] === 'https') ? 'on' : 'off';
				}
				//set HTTP_HOST?
				if(!isset($_SERVER['HTTP_HOST'])) {
					$_SERVER['HTTP_HOST'] = (isset($parseUrl['host']) && $parseUrl['host']) ? $parseUrl['host'] : '';
				}
				//set REQUEST_URI?
				if(!isset($_SERVER['REQUEST_URI'])) {
					$_SERVER['REQUEST_URI'] = (isset($parseUrl['path']) && $parseUrl['path']) ? $parseUrl['path'] : '/';
				}
				//set PATH_INFO?
				if($route) {
					$_SERVER['PATH_INFO'] = '/' . $route;
					$_SERVER['REQUEST_URI'] = rtrim($_SERVER['REQUEST_URI'], '/') . $_SERVER['PATH_INFO'];
				}
				//set SCRIPT_NAME?
				if(!isset($_SERVER['SCRIPT_NAME'])) {
					$_SERVER['SCRIPT_NAME'] = rtrim($_SERVER['REQUEST_URI'], '/') . '/index.php';
				}
				//remove base dir
				$_SERVER['SCRIPT_NAME'] = str_replace($baseDir, '', $_SERVER['SCRIPT_NAME']);
			}
			//url components
			$method = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
			$port = (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 80;
			$ssl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? ($_SERVER['HTTPS'] !== 'off') : ($port == 443);
			$domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
			$host = 'http' . ($ssl ? 's' : '') . '://' . $domain;
			$reqBase = explode('?', $_SERVER['REQUEST_URI'])[0];
			$scriptBase = $formatDir(dirname(explode('.php', $_SERVER['SCRIPT_NAME'])[0])) ?: '/';
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
				'env_opts' => [ 'dev', 'staging', 'prod' ],
				'debug' => null,
				'cli' => $isCli,
				'included' => $incFrom !== $scriptDir,
				'autorun' => 'constructor',
				'webcron' => true,
				//dirs
				'base_dir' => $baseDir,
				'vendor_dirs' => array_unique([ $baseDir . '/vendor', $formatDir(dirname(__DIR__)) ]),
				'cache_dir' => $baseDir . '/cache',
				'config_dir' => $baseDir . '/config',
				'logs_dir' => $baseDir . '/logs',
				'modules_dir' => $baseDir . '/modules',
				//url
				'ssl' => $ssl,
				'domain' => $domain,
				'host' => $host,
				'port' => $port,
				'url' => $host . $_SERVER['REQUEST_URI'],
				'base_url' => null,
				'pathinfo' => trim(str_replace(($scriptBase === '/' ? '' : $scriptBase), '', $reqBase), '/'),
				'method' => $method,
				'allowed_hosts' => [],
				//modules
				'modules' => [],
				'module_loading' => '',
				//other
				'name' => '',
				'instance' => 'default',
				'namespace' => null,
				'version' => null,
				'theme' => null,
				'annotations' => false,
				'log_http_errors' => true,
				'db_services' => [ 'db' ],
			], $this->config);
			//cache instance
			self::$_instances[$this->config['instance']] = $this->services['kernel'] = $this;
			//format base url
			$this->config['base_url'] = $this->config['base_url'] ?: ($host . '/' . trim($scriptBase, '/'));
			$this->config['base_url'] = rtrim($this->config['base_url'], '/') . '/';
			//set app name?
			if(!$this->config['name']) {
				$this->config['name'] = ucfirst(explode('.', $_SERVER['HTTP_HOST'])[0]);
			}
			//script included?
			if($this->config['included']) {
				$this->config['autorun'] = null;
			}
			//config file store
			$configFiles = [];
			//check config directory for matches
			foreach(glob($this->config['config_dir'] . '/*.php') as $file) {
				//look for env
				$parts = explode('.', pathinfo($file, PATHINFO_FILENAME), 2);
				$env = isset($parts[1]) ? $parts[1] : 'global';
				//add file
				$configFiles[] = [ $env, $file ];
			}
			//merge global config files
			foreach($configFiles as $file) {
				//env match?
				if($file[0] === 'global') {
					//include file
					$conf = include($file[1]);
					//merge config?
					if($conf && is_array($conf)) {
						$this->config = array_merge($this->config, $conf);
					}
				}
			}
			//check allowed hosts?
			if($domain && $this->config['allowed_hosts']) {
				//valid host?
				if(isset($this->config['allowed_hosts'][$domain])) {
					//empty env?
					if(!$this->config['env']) {
						//get tags array
						$tags = (array) $this->config['allowed_hosts'][$domain];
						//loop through env opts
						foreach($this->config['env_opts'] as $e) {
							//match found?
							if(in_array($e, $tags)) {
								$this->config['env'] = $e;
								break;
							}
						}
					}
				} else {
					$domain = false;
				}
			}
			//bad request?
			if(!$domain) {
				http_response_code(400);
				die();
			}
			//set default env?
			if(!$this->config['env']) {
				$this->config['env'] = 'prod';
			}
			//merge env config files
			foreach($configFiles as $file) {
				//env match?
				if($file[0] === $this->config['env']) {
					//include file
					$conf = include($file[1]);
					//merge config?
					if($conf && is_array($conf)) {
						$this->config = array_merge($this->config, $conf);
					}
				}
			}
			//merge env local config?
			if(isset($opts["config." . $this->config['env']])) {
				$this->config = array_merge($this->config, $opts["config." . $this->config['env']]);
			}
			//set default debug?
			if($this->config['debug'] === null) {
				$this->config['debug'] = ($this->config['env'] === 'dev');
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
				if(is_numeric($httpCode) && ($httpCode < 100 || $httpCode >= 500)) {
					//build error message
					$errMsg = 'HTTP SERVER: Unexpected response code ' . $httpCode . ' | ' . $this->config['method'] . ' ' . $this->config['url'];
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
					mkdir($dir, 0755);
				}
			}
			//default file loader
			spl_autoload_register($this->bind(function($class) use($formatDir) {
				//loop through paths
				foreach($this->config['vendor_dirs'] as $path) {
					//get file path
					$path .= '/' . $formatDir($class) . '.php';
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
				//set vars
				$match = [];
				$name = basename($dir);
				$meta = isset($whitelist[$name]) ? $whitelist[$name] : [];
				//whitelist match?
				if($whitelist && !isset($whitelist[$name]) && !in_array($name, $whitelist)) {
					continue;
				}
				//text-based matches
				foreach($meta as $k => $v) {
					if($k && $v) {
						$match[] = $k . '=' . $v;
					}
				}
				//can load module?
				if(!$match || $this->isMatch($match)) {
					$this->module($name);
				}
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

		public function isDebug() {
			return ($this->config['debug'] === true);
		}	

		public function isMatch($args) {
			//format args?
			if(func_num_args() > 1) {
				$args = func_get_args();
			} else if(!is_array($args)) {
				$args = array_map('trim', explode('&', $args));
			}
			//loop through args
			foreach($args as $arg) {
				//parse arg
				list($k, $v) = explode('=', $arg, 2);
				//tags match?
				if($k === 'tags' && $v) {
					$vArr = array_map('trim', explode(',', $v));
					$hosts = $this->config['allowed_hosts'];
					$tags = isset($hosts[$this->config['domain']]) ? $hosts[$this->config['domain']] : [];
					foreach($vArr as $t) {
						if($t && !in_array($t, $tags)) {
							return false;
						}
					}
				}
				//function match?
				if($k === 'function' && $v && !function_exists($v)) {
					return false;
				}
				//platform match?
				if($k === 'platform' && $v && $this->platform() !== $v) {
					return false;
				}
				//pathinfo match?
				if($k === 'pathinfo' && $v && strpos($this->config['pathinfo'], $v) !== 0) {
					return false;
				}
			}
			//return
			return true;
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

		public function caller($key=null) {
			//exception trace
			$ex = new \Exception;
			$trace = $ex->getTrace();
			$arr = isset($trace[1]) ? $trace[1] : [];
			//return
			return $key ? (isset($arr[$key]) ? $arr[$key] : null) : $arr;
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
			foreach([ $this->config['namespace'], __NAMESPACE__ ] as $ns) {
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
			//default opts
			$opts = array_merge([
				'module' => '',
				'caller' => '',
				'relative' => false,
				'validate' => true,
			], $opts);
			//set vars
			$path = $path ?: '';
			$baseDir = $this->config['base_dir'];
			$modulesDir = $this->config['modules_dir'];
			$moduleNames = array_keys($this->config['modules']);
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
			$checkPaths = array_unique(array_merge([ $this->config['theme'] ], $moduleNames, [ $baseDir ]));
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
			$path = trim($path ?: $this->config['url']);
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
				$path = $this->config['base_url'] . $path;
				//add timestamp?
				if($opts['time'] && strpos($tmp, '.') !== false) {
					//get file
					$file = $this->config['base_dir'] . '/' . $tmp;
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
			//setitem?
			if($key && $val !== '%%null%%') {
				$this->config = Utils::addToArray($this->config, $key, $val);
			}
			//get result
			if($res = Utils::getFromArray($this->config, $key)) {
				//cache instance
				$ctx = $this;
				//config placeholders
				$res = Utils::updatePlaceholders($res, $this->config, '%', '%');
				//service placeholders
				$res = Utils::updatePlaceholders($res, function($key) use($ctx) {
					return $ctx->service($key);
				}, '[', ']');
			}
			//return
			return $res;
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
				//set vars
				$vendor = false;
				$prev = $this->config['module_loading'];
				$dir = $this->config['modules_dir'] . '/' . $name;
				//valid module?
				if(!is_dir($dir)) {
					throw new \Exception("Module $name does not exist");
				}
				//update loading value
				$this->config['module_loading'] = $name;
				//cache module
				$this->config['modules'][$name] = null;
				//add vendor dir?
				if(is_dir($dir . '/vendor')) {
					array_unshift($this->config['vendor_dirs'], $dir . '/vendor');
					$vendor = true;
				}
				//bootstrap module?
				if(is_file($dir . '/module.php')) {
					//create closure
					$moduleFn = $this->bind(function($__file) {
						return require_once($__file);
					});
					//has return statement?
					if($ret = $moduleFn($dir . '/module.php')) {
						$this->config['modules'][$name] = $ret;
					}
					//remove vendor dir?
					if($vendor && $ret === false) {
						array_shift($this->config['vendor_dirs']);
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
				} else if(is_object($obj)) {
					$this->services[$name] = $obj;
					$this->config("{$name}_class", get_class($obj));
				} else {
					$this->config("{$name}_class", $obj);
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
			if($class && $this->config['annotations']) {
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
			if(is_scalar($route) && !is_object($callback) && !is_callable($callback)) {
				//format path
				$path = trim($route ?: '', '/');
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
			//format route?
			if(is_scalar($route)) {
				//set path
				$path = trim($route ?: '', '/');
				$route = [];
				//set callback as route?
				if(is_object($callback) && !is_callable($callback)) {
					$route = $callback;
					$callback = null;
				}
			} else {
				//set path
				$path = trim($route['path'], '/');
			}
			//is array?
			if(is_array($route)) {
				//set defaults
				$route = array_merge([
					'callback' => null,
					'methods' => [],
					'auth' => null,
				], $route);
			}
			//valid callback?
			if(is_callable($callback)) {
				$route['callback'] = $callback;
			} else if(!is_callable($route['callback'])) {
				throw new \Exception("Route $path requires a valid callback");
			}
			//bind callback
			$route['callback'] = $this->bind($route['callback']);
			//set module
			$route['module'] = $this->config['module_loading'];
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
			$res = $this->cache($this->config['logs_dir'] . "/{$name}.log", $data, [ 'append' => true ]);
			//log event
			$this->event('app.log', [
				'name' => $name,
				'data' => ($data === '%%null%%') ? null : $data,
			]);
			//return
			return $res;
		}

		public function cache($path, $data='%%null%%', array $opts=[]) {
			//default opts
			$opts = array_merge([
				'append' => false,
				'expiry' => 0,
			], $opts);
			//add base path?
			if(strpos($path, '/') !== 0 && strpos($path, ':') === false) {
				$path = $this->config['cache_dir'] . '/' . $path;
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
			if($data !== '%%null%%') {
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
				$res = file_put_contents($path, $data, $opts['append'] ? LOCK_EX|FILE_APPEND : LOCK_EX);
				//return
				return ($res !== false);
			}
			//default output
			$output = null;
			//get file output?
			if(is_file($path)) {
				//get data
				$output = file_get_contents($path);
				//data found?
				if($output !== false) {
					//try to decode output
					$decode = json_decode($output, true);
					$output = is_null($decode) ? $output : $decode;
					//has cache expiry?
					if(is_array($output) && count($output) == 2 && isset($output['expiry'])) {
						//has expired?
						if(time() > $output['expiry']) {
							$output = null;
							unlink($path);
						} else {
							$output = $output['data'];
						}
					}
				} else {
					//no output
					$output = null;
				}
			}
			//return
			return $output;
		}

		public function memoize($id, $fn=null, $cacheExpiry=null) {
			//set vars
			$results = [];
			$kernel = $this;
			//ID is closure?
			if($id instanceof \Closure) {
				$cacheExpiry = $fn;
				$fn = $id;
				$id = '';
			}
			//create function wrapper
			return function() use($id, $fn, $cacheExpiry, &$results, $kernel) {	
				//get args
				$args = func_get_args();
				//create cache ID
				$id = 'memoize/' . md5($id . serialize($args));
				//in local cache?
				if(!isset($results[$id])) {
					//check file cache?
					if($cacheExpiry !== null) {
						$results[$id] = $kernel->cache($id);
					}
					//call function?
					if(!isset($results[$id])) {
						//execute function
						$results[$id] = call_user_func_array($fn, $args);
						//save to file cache?
						if($results[$id] !== null && $cacheExpiry !== null) {
							$kernel->cache($id, $results[$id], [ 'expiry' => $cacheExpiry ]);
						}
					}
				}
				//return
				return $results[$id];
			};
		}

		public function input($key=[], array $opts=[]) {
			$_GET; $_POST; $_COOKIE; $_REQUEST; $_SERVER;
			//set vars
			$isHeader = false;
			$useReqMethod = true;
			$key = $key ? explode('.', $key) : [];
			$globVars = [ 'GET', 'POST', 'COOKIE', 'REQUEST', 'SERVER' ];
			$postVars = [ 'POST', 'PUT', 'PATCH' ];
			//set default opts
			$opts = array_merge([
				'clean' => 'html',
				'default' => null,
			], $opts);
			//process key?
			if(!empty($key)) {
				//format first segment
				$tmp = strtoupper($key[0]);
				//global matched?
				if(in_array($tmp, $globVars)) {
					$key[0] = '_' . $tmp;
					$useReqMethod = false;
				} if(in_array($tmp, $postVars)) {
					$key[0] = '_POST';
					$useReqMethod = false;
				} else if($tmp === 'HEADER') {
					$key[0] = '_SERVER';
					$useReqMethod = false;
					$isHeader = true;
				}
			}
			//use request method?
			if(!$key || $useReqMethod) {
				array_unshift($key, in_array($this->config['method'], $postVars) ? '_POST' : '_GET');
			}
			//set value
			$value = $GLOBALS;
			//loop through key
			foreach($key as $k => $v) {
				//is header?
				if($isHeader && count($key) == ($k+1)) {
					//add http prefix?
					if(stripos($v, 'HTTP_') !== 0) {
						$v = 'HTTP_' . $v;
					}
					//uppercase
					$v = strtoupper($v);
				}
				//value found?
				if(!isset($value[$v])) {
					return $opts['default'];
				}
				//next segment
				$value = $value[$v];
			}
			//return
			return $this->clean($value, $opts['clean']);
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
						$keys = array_keys($data);
						$index = array_search('code', $keys, true);
						$pos = ($index === false) ? count($data) : $index + 1;
						$msg = [ 'message' => $this->httpMessages[$code] ];
						$data = array_merge(array_slice($data, 0, $pos), $msg, array_slice($data, $pos));
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
			//is localhost?
			$localhost = in_array($parse['host'], [ 'localhost', '127.0.0.1', '::1' ]);
			//set context
			$context = stream_context_create([
				'ssl' => [
					'allow_self_signed' => $localhost,
                    'verify_peer' => !$localhost,
                    'verify_peer_name' => !$localhost
				]
			]);
			//attempt connection?
			if($fp = stream_socket_client($parse['scheme'] . '://' . $parse['host'] . ':' . $parse['port'], $errno, $errmsg, $opts['timeout'], STREAM_CLIENT_CONNECT, $context)) {
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
				//parse json?
				if($body && isset($headers['content-type']) && stripos($headers['content-type'], 'json') !== false) {
					//decode body
					$body = json_decode($body, true);
					//check for json error?
					if($error = json_last_error()) {
						//has error?
						if($error != JSON_ERROR_NONE) {
							$this->log('errors', 'HTTP CLIENT: Invalid json response | ' . $opts['method'] . ' ' . $opts['url']);
						}
					}
				}
				//wrap response?
				if($opts['response_headers']) {
					$response = [ 'status' => $status, 'headers' => $headers, 'body' => $body ];
				} else {
					$response = $body;
				}
				//log response error?
				if(($status < 100 || $status >= 400) && $this->config('log_http_errors')) {
					$this->log('errors', 'HTTP CLIENT: Unexpected response code ' . $status . ' | ' . $opts['method'] . ' ' . $opts['url']);
				}
			}
			//return
			return $response;
		}

		public function mail($to, $subject, $body, array $opts=[]) {
			//set vars
			$headers = '';
			$separator = md5(uniqid(time()));
			$to = is_string($to) ? explode(',', $to) : $to;
			//set defaults
			$opts = array_merge([
				'subject' => trim($subject),
				'body' => trim($body),
				'to' => array_map('trim', $to),
				'from' => $this->config('mail_from') ?: 'no-reply@' . $this->input('SERVER.HTTP_HOST'),
				'from_name' => $this->config('mail_name') ?: $this->config['name'],
				'headers' => [],
				'attachments' => [],
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
				'MIME-Version' => $opts['html'] ? '1.0' : '',
			], $opts['headers']);
			//mail event
			$opts = $this->event('app.mail', $opts);
			//return now?
			if(!is_array($opts)) {
				return !!$opts;
			}
			//format to emails?
			if(is_array($opts['to'])) {
				$opts['to'] = implode(', ', $opts['to']);
			}
			//valid from email?
			if(!filter_var($opts['from'], FILTER_VALIDATE_EMAIL)) {
				throw new \Exception("Invalid From email address");
			}
			//force mixed content type
			$opts['headers']['Content-Type'] = 'multipart/mixed; boundary="' . $separator . '"';
			//convert headers to string
			foreach($opts['headers'] as $k => $v) {
				if(!empty($v)) {
					$headers .= ucfirst($k) . ': ' . $v . "\r\n";
				}
			}
			//open body
			$body  = '--' . $separator . "\r\n";
			$body .= 'Content-type: text/' . ($opts['html'] ? 'html' : 'plain') . '; charset=utf-8' . "\r\n\r\n";
			$body .= $opts['body'] . "\r\n";
			//add attachments
			foreach($opts['attachments'] as $k => $v) {
				$body .= '--' . $separator . "\r\n";
				$body .= 'Content-Type: application/octet-stream; name="' . $k . '"' . "\r\n";
				$body .= 'Content-Disposition: attachment; filename="' . $k . '"' . "\r\n";
				$body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
				$body .= base64_encode($v) . "\r\n";
			}
			//close body
			$body .= '--' . $separator . '--';
			//use safe mode?
			if(ini_get('safe_mode')) {
				return mail($opts['to'], $opts['subject'], $body, $headers);
			} else {
				return mail($opts['to'], $opts['subject'], $body, $headers, '-f' . $opts['from']);
			}
			//return
			return $result;
		}

		public function form($name, $method='post', $action='') {
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
			//create form object
			return HtmlForm::factory($name, $opts);
		}

		public function table($name, array $opts = []) {
			//format opts?
			if($opts && !isset($opts['data'])) {
				$opts = [ 'data' => $opts ];
			}
			//add kernel
			$opts['kernel'] = $this;
			//create table object
			return HtmlTable::factory($name, $opts);
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
				if(!isset($_GET['cron']) && $this->config['webcron'] && !$this->config['cli']) {
					//call now?
					if($next <= time() && !$isRunning) {
						$url = $this->config['base_url'] . '?cron=' . time();
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
			$pathInfo = (string) $this->config['pathinfo'];
			$webCron = $this->config['webcron'] && !$this->config['cli'];
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
				$params = [];
				$target = $path;
				//is fallback path?
				if(preg_match('/(^|\/)' . $fallback . '(\/|$)/', $target, $m)) {
					$m[0] = str_replace($fallback, '(.*)', $m[0]);
					$target = preg_replace('/(^|\/)' . $fallback . '(\/|$)/', $m[0], $target);
				}
				//valid method?
				if($route['methods'] && !in_array($this->config['method'], $route['methods'])) {
					continue;
				}
				//extract params
				foreach(explode('/', $target) as $seg) {
					if($seg && $seg[0] === ':') {
						$seg = str_replace([ ':', '?' ], '', $seg);
						$params[$seg] = null;
						$target = str_replace("/:$seg", "(\/.*)", $target);
					}
				}
				//build regex
				$regex = '/^' . str_replace([ '/', '\\\\' ], [ '\/', '\\' ], $target) . '$/';
				//valid route?
				if(!preg_match($regex, $pathInfo, $matches)) {
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
			//show debug bar?
			if($this->config('debug_bar') || isset($_GET['debugger'])) {
				//get headers
				$headers = preg_replace("/\s+/", "", implode("\n", headers_list()));
				//is valid html request?
				if(stripos($headers, 'content-type') === false || stripos($headers, 'content-type:text/html') !== false) {
					$output = str_replace('</body>', $this->debug(true) . '</body>', $output);
				}
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
			//cache dbs
			$dbCache = [];
			//loop through db objects
			foreach($this->config('db_services') as $key) {
				//skip object?
				if(!isset($this->services[$key]) || ($this->services[$key] instanceOf \Closure)) {
					continue;
				}
				//get db
				$db = $this->services[$key];
				//get db name
				$dbname = isset($db->dbname) ? $db->dbname : $db->name;
				//already processed?
				if(!in_array($dbname, $dbCache)) {
					//add to cache
					$dbCache[] = $dbname;
					//count queries
					$data['queries'] += count($db->queries);
					//get query log
					$data['queries_log'][$dbname] = array_map(function($item) {
						//set vars
						$result = [];
						//is array?
						if(is_array($item)) {
							$result['query'] = $item[0];
							$result['time'] = isset($item[1]) ? number_format($item[1], 5) : 0;
						} else {
							$result['query'] = $item;
							$result['time'] = 0;
						}
						//return
						return $result;
					}, $db->queries);
				}
			}
			//stop here?
			if(!$asHtml) {
				return $data;
			}
			//create html
			$html  = '<div id="debug-bar" style="font-size:13px; padding:10px; margin-top:15px; background:#dfdfdf;">';
			$html .= '<div class="heading" onclick="return this.nextSibling.style.display=\'block\';">';
			$html .= '<span style="font-weight:bold;">Debug bar:</span> &nbsp;' . $data['time'] . ' &nbsp;|&nbsp; ' . $data['mem'] . ' &nbsp;|&nbsp; ';
			$html .= '<span style="color:blue;cursor:pointer;">' . $data['queries'] . ' queries &raquo;</span>';
			$html .= '</div>';
			if($data['queries_log']) {
				$html .= '<div class="queries" style="display:none;">';
				foreach($data['queries_log'] as $name => $queries) {
					$html .= '<p>DB name: ' . $name . '</p>';
					$html .= '<ol style="padding-left:20px; margin-left:0;">';
					foreach($queries as $q) {
						if($q['time'] > 0.1) {
							$q['time'] = '<span style="color:red;">' . $q['time'] . '</span>';
						}
						$html .= '<li style="margin:8px 0 0 0; line-height:1.1;">' . $q['query'] . ' | ' . $q['time'] . '</li>';
					}
					$html .= '</ol>';
				}
				$html .= '</div>';
			} else {
				$html .= '<div class="no-queries" style="display:none;">Query log empty</div>';
			}
			$html .= '</div>';
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
				$file = isset($item['file']) ? str_replace($this->config['base_dir'] . '/', '', $item['file']) : '';
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
				'file' => str_replace($this->config['base_dir'] . '/', '', $e->getFile()),
				'trace' => $e->getTraceAsString(),
				'debug' => isset($e->debug) ? $e->debug : [],
				'display' => $display,
			];
			//custom error handling?
			if($error = $this->event('app.error', $error)) {
				//build error message
				$errMsg = 'PHP ' . $error['type'] . ': ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'];
				$errMsgLog = $errMsg . ' (' . $this->config['method'] . ' ' . $this->config['url'] . ')';
				//php error log
				error_log($errMsgLog);
				//app log error
				$this->log('errors', $errMsgLog);
				//display error?
				if($error['display']) {
					//use callable?
					if(is_callable($error['display'])) {
						//custom output
						call_user_func($error['display']);
					} else if(isset($this->routes['500'])) {
						//route output
						$this->route('500', [ 'error' => $error ]);
					} else if($this->isDebug()) {
						//debug output
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
						//standard output
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
			//set vars
			$callback = isset($this->__calls[$method]) ? $this->__calls[$method] : null;
			//has callback?
			if($callback) {
				return $callback(...$args);
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