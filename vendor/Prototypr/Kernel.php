<?php

namespace {

	require_once(__DIR__ . '/ExtendTrait.php');

	function prototypr($opts=[]) {
		return \Prototypr\Kernel::instance($opts);
	}

}

namespace Prototypr {

	class Kernel {
	
		use ExtendTrait;

		const VERSION = '1.0.1';

		private static $_instances = [];

		private $_hasRun = false;
		private $_startMem = 0;
		private $_startTime = 0;

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

		public final static function instance($opts=[]) {
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
			$cli = (php_sapi_name() === 'cli');
			$incFrom = explode('/vendor/', str_replace("\\", "/", dirname(array_reverse(get_included_files())[1])))[0];
			$baseDir = (isset($opts['base_dir']) && $opts['base_dir']) ? $opts['base_dir'] : $incFrom;
			$baseUrl = (isset($opts['base_url']) && $opts['base_url']) ? $opts['base_url'] : '';
			//is cli?
			if($cli !== false) {
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
				//emulate vars
				$_SERVER['HTTPS'] = (isset($parse['scheme']) && $parse['scheme'] === 'https') ? 'on' : 'off';
				$_SERVER['HTTP_HOST'] = (isset($parse['host']) && $parse['host']) ? $parse['host'] : '';
				$_SERVER['REQUEST_URI'] = (isset($parse['path']) && $parse['path']) ? $parse['path'] : '/';
				$_SERVER['SCRIPT_NAME'] = rtrim($_SERVER['REQUEST_URI'], '/') . '/index.php';
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
				'cli' => $cli,
				'included' => $incFrom !== dirname($_SERVER['SCRIPT_FILENAME']),
				'autorun' => 'constructor',
				'webcron' => true,
				//dirs
				'base_dir' => $baseDir,
				'vendor_dirs' => [ $baseDir . '/vendor' ],
				'cache_dir' => $baseDir . '/data/cache',
				'config_dir' => $baseDir . '/data/config',
				'logs_dir' => $baseDir . '/data/logs',
				'schemas_dir' => $baseDir . '/data/schemas',
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
				'version' => null,
				'theme' => null,
				'custom_error_log' => true,
			], $this->config);
			//cache instance
			self::$_instances[$this->config['instance']] = $this;
			//format base url
			$this->config['base_url'] = $this->config['base_url'] ?: ($host . '/' . trim($scriptBase, '/'));
			$this->config['base_url'] = rtrim($this->config['base_url'], '/') . '/';
			//script included?
			if($this->config['included']) {
				$this->config['autorun'] = null;
				$this->config['custom_error_log'] = false;
			}
			//guess env?
			if(!$this->config['env']) {
				//set default
				$env = $this->config['env'] = 'prod';
				//scan for env in IP / HOST
				if(isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], [ '127.0.0.1', "::1" ])) {
					$env = 'dev';
				} else if(isset($_SERVER['HTTP_HOST'])) {
					$env = explode('.', $_SERVER['HTTP_HOST'])[0];
					$env = explode('-', $env)[0];
				}
				//match found?
				if(in_array($env, $this->envs)) {
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
			//custom error handling?
			if(!$this->config['included']) {
				//error reporting
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
					//get last error
					$error = error_get_last();
					//log exception?
					if($error && in_array($error['type'], [ E_ERROR, E_CORE_ERROR ])) {
						$this->logException(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
					}
				}));
			}
			//default file loader
			spl_autoload_register($this->bind(function($__class) {
				//loop through paths
				foreach($this->config('vendor_dirs') as $__path) {
					//get file path
					$__path .= '/' . str_replace('\\', '/', $__class) . '.php';
					//file exists?
					if(is_file($__path)) {
						require_once($__path);
					}
				}
			}));
			//default services
			$this->services = array_merge([
				'api' => function(array $opts, $class) {
					//create object
					return new $class($opts);
				},
				'composer' => function(array $opts, $class) {
					//create object
					return new $class(array_merge([
						'baseDir' => $this->config('base_dir'),
					], $opts));
				},
				'db' => function(array $opts, $class) {
					//create object
					return new $class($opts);
				},
				'orm' => function(array $opts, $class) {
					//create object
					return new $class($opts);
				},
				'platform' => function(array $opts, $class) {
					//create object
					return new $class($opts);
				},
				'validator' => function(array $opts, $class) {
					//create object
					return new $class($opts);
				},
				'view' => function(array $opts, $class) {
					//create object
					return new $class($opts);
				},
			], $this->services);
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
				//sync global db schemas
				foreach(glob($this->config['schemas_dir'] . '/*.sql') as $file) {
					$this->db->schema($file);
				}
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
			//is service?
			return isset($this->services[$key]);
		}

		public function __get($key) {
			//is service?
			if(isset($this->services[$key])) {
				return $this->service($key);
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
			//set vars
			$thisArg = $thisArg ?: $this;
			//bind closure?
			if($callable instanceof \Closure) {
				$callable = \Closure::bind($callable, $thisArg, $thisArg);
			}
			//return
			return $callable;
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
			if($obj !== '%%null%%') {
				$this->services[$name] = $this->bind($obj);
				return $obj;
			}
			//has service?
			if(!isset($this->services[$name])) {
				return null;
			}
			//execute closure?
			if($this->services[$name] instanceof \Closure) {
				//get class
				$class = $this->config($name . '_class') ?: __NAMESPACE__ . '\\' . ucfirst($name);
				//get opts
				$opts = $this->config($name . '_opts') ?: [];
				//add kernel
				$opts['kernel'] = $this;
				//create service
				$this->services[$name] = $this->services[$name]($opts, $class);
			}
			//get service
			return $this->services[$name];
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
			//use default error log?
			if($name === 'errors' && $data !== '%%null%%') {
				if(!$this->config('custom_error_log')) {
					return error_log(explode("]", $data, 2)[1]);
				}
			}
			//use custom log
			return $this->cache($this->config('logs_dir') . "/{$name}.log", $data, true);	
		}

		public function cache($path, $data='%%null%%', $append=false) {
			//set vars
			$output = false;
			$closure = ($data instanceof \Closure);
			//add path?
			if(strpos($path, '/') === false) {
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
				//encode data?
				if(!is_string($data) && !is_numeric($data)) {
					$data = json_encode($data, JSON_PRETTY_PRINT);
				}
				//append data?
				if($append) {
					$data  = ltrim($data) . "\n";
				}
				//save to file
				return file_put_contents($path, $data, $append ? LOCK_EX|FILE_APPEND : LOCK_EX);
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
			//ensure globals set
			$_GET; $_POST; $_COOKIE; $_REQUEST; $_SERVER;
			//compound key?
			if($key && strpos($key, '.') !== false) {
				list($global, $key) = explode('.', $key, 2);
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
			$data = $this->event('output.json', $data);
			//set content-type
			header("Content-Type: application/json");
			//display
			echo json_encode($data, JSON_PRETTY_PRINT);
		}

		public function http($url, array $opts=[]) {
			//default opts
			$opts = array_merge([
				'query' => [],
				'method' => '',
				'headers' => [],
				'body' => '',
				'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.81 Safari/537.36',
				'protocol' => '1.0',
				'timeout' => 5,
				'blocking' => 1,
				'response_headers' => 0,
			], $opts);
			//format method
			$opts['method'] = strtoupper(trim($opts['method'])) ?: 'GET';
			//add query string?
			if($opts['query']) {
				$url .= (strpos($url, '?') === false) ? '?' : '&';
				$url .= (is_array($opts['query']) ? http_build_query($opts['query']) : $opts['query']);
			}
			//format headers?
			if(is_array($opts['headers'])) {
				$opts['headers'] = trim(implode("\r\n", $opts['headers']));
			}
			//format body?
			if(is_array($opts['body'])) {
				$opts['body'] = http_build_query($opts['body']);
			}
			//set content type?
			if($opts['body'] && stripos($opts['headers'], 'Content-Type:') === false) {
				$opts['headers'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
			}
			//set user agent?
			if($opts['user_agent'] && stripos($opts['headers'], 'User-Agent:') === false) {
				$opts['headers'] .= "\r\nUser-Agent: " . $opts['user_agent'];
			}
			//parse url
			$status = 0;
			$response = '';
			$parse = parse_url($url);
			$scheme = ($parse['scheme'] === 'https') ? 'ssl' : 'tcp';
			$port = ($parse['scheme'] === 'https') ? 443 : 80;
			//successful connection?
			if($fp = fsockopen($scheme . '://' . $parse['host'], $port, $errno, $errstr, $opts['timeout'])) {
				//set stream options
				stream_set_timeout($fp, $opts['timeout']);
				stream_set_blocking($fp, $opts['blocking']);
				//set request headers
				$request  = $opts['method'] . " " . (isset($parse['path']) ? $parse['path'] : '/') . (isset($parse['query']) ? '?' . $parse['query'] : '') . " HTTP/" . $opts['protocol'] . "\r\n";
				$request .= "Host: " . $parse['host'] . "\r\n";
				$request .= $opts['headers'] ? trim($opts['headers']) . "\r\n" : "";
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
					$status = $match[1];
					unset($headers[0]);
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
			}
			//return
			return $response;
		}

		public function model($name, $data=[], $find=true) {
			//select method
			$method = ($find && $data) ? 'load' : 'create';
			//return model
			return $this->orm->$method($name, $data);
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

		public function logException($e, $display=true) {
			//set vars
			$severity = '';
			//get severity?
			if(method_exists($e, 'getSeverity')) {
				$names = [];
				$severity = $e->getSeverity();
				$consts = array_flip(array_slice(get_defined_constants(true)['Core'], 0, 15, true));
				foreach($consts as $code => $name) {
					if($severity & $code) {
						$names[] = $name;
					}
				}
				$severity = implode(', ', $names);
			}
			//error parts
			$error = [
				'date' => date('Y-m-d H:i:s'),
				'type' => $severity ? $severity : get_class($e),
				'message' => $e->getMessage(),
				'line' => $e->getLine(),
				'file' => $e->getFile(),
				'trace' => $e->getTraceAsString(),
				'debug' => isset($e->debug) ? $e->debug : [],
				'display' => $display,
			];
			//custom error handling?
			if($error = $this->event('app.error', $error)) {
				//meta data
				$meta = $error['type'] . " in " . str_replace($this->config('base_dir') . '/', '', $error['file']) . " on line " . $error['line'];
				//log error
				$this->log('errors', "[" . $error['date'] . "]\n" . $meta . "\n" . $error['message'] . "\n");
				//display error?
				if($error['display']) {
					if($this->isEnv('dev')) {
						echo '<div class="error" style="margin:1em 0; padding: 0.5em; border:1px red solid;">';
						echo $meta;
						echo '<br><br>';
						echo $error['message'];
						echo '<br><br>';
						foreach($error['debug'] as $k => $v) {
							echo '<b>' . $k . '</b>: ' . var_export($v, true);
							echo '<br><br>';
						}
						echo str_replace("\n", "<br>", $error['trace']);
						echo '</div>';
						echo "\n";
					} else {
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

}