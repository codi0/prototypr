<?php

namespace {

	function prototypr($opts=[]) {
		//set vars
		static $cache = [];
		$instance = 'default';
		//instance string?
		if(!is_array($opts)) {
			$instance = $opts;
			$opts = [];
		}
		//instance array?
		if(isset($opts['instance']) && $opts['instance']) {
			$instance = $opts['instance'];
		}
		//create instance?
		if(!isset($cache[$instance])) {
			$cache[$instance] = new \Codi0\Prototypr\App($opts);
		}
		//return
		return $cache[$instance];
	}

}

namespace Codi0\Prototypr {

	class App {

		private $config = [];
		private $helpers = [];
		private $services = [];
		private $events = [];
		private $cron = [];
		private $routes = [];
	
		private $_hasRun = false;
		private $_startTime = 0;
		private $_startMem = 0;

		public function __construct(array $opts=[]) {
			//debug
			$this->_startTime = microtime(true);
			$this->_startMem = memory_get_usage();
			//set vars
			$app = $this;
			$ssl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? ($_SERVER['HTTPS'] !== 'off') : (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
			$host = (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']) ? 'http' . ($ssl ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] : '';
			$baseDir = dirname($_SERVER['SCRIPT_FILENAME']);
			$baseUri = dirname($_SERVER['SCRIPT_NAME']) ?: '/';
			$reqUri = (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
			$reqUriBase = explode('?', $reqUri)[0];
			//loop through opts
			foreach($opts as $k => $v) {
				if(property_exists($this, $k)) {
					$this->$k = $v;
				}
			}
			//set config defaults
			$this->config = array_merge([
				'autoRun' => true,
				'webCron' => true,
				'router' => true,
				'env' => 'dev',
				'baseDir' => $baseDir,
				'cacheDir' => $baseDir . '/data/cache',
				'logsDir' => $baseDir . '/data/logs',
				'schemasDir' => $baseDir . '/data/schemas',
				'modulesDir' => $baseDir . '/modules',
				'vendorDirs' => [ $baseDir . '/vendor' ],
				'ssl' => $ssl,
				'host' => $host,
				'url' => $host . $reqUri,
				'baseUrl' => $host . str_replace('//', '/', '/' . trim($baseUri, '/') . '/'),
				'pathInfo' => trim(str_replace(($baseUri === '/' ? '' : $baseUri), '', $reqUriBase), '/'),
				'composer' => [],
				'modules' => [],
				'moduleLoading' => '',
				'modulesDisabled' => [],
				'theme' => 'theme',
			], $this->config);
			//merge env config?
			if(isset($opts["config." . $this->config['env']])) {
				$this->config = array_merge($this->config, $opts["config." . $this->config['env']]);
			}
			//error reporting
			error_reporting(E_ALL);
			ini_set('log_errors', 0);
			ini_set('display_errors', 0);
			ini_set('display_startup_errors', 0);
			//exception handler
			set_exception_handler([ $this, 'logException' ]);
			//error handler
			set_error_handler(function($type, $message, $file, $line) use($app) {
				$app->logException(new \ErrorException($message, 0, $type, $file, $line));
			});
			//fatal error handler
			register_shutdown_function(function() use($app) {
				//get last error
				$error = error_get_last();
				//log exception?
				if($error && in_array($error['type'], [ E_ERROR, E_CORE_ERROR ])) {
					$app->logException(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
				}
			});
			//default file loader
			spl_autoload_register(function($class) use($app) {
				//loop through paths
				foreach($app->config('vendorDirs') as $path) {
					//get file path
					$file = $path . '/' . str_replace('\\', '/', $class) . '.php';
					//file exists?
					if(is_file($file)) {
						require($file);
					}
				}
			});
			//default services
			$this->services = array_merge([
				'composer' => function($app) {
					return new Composer(array_merge([ 'baseDir' => $app->config('baseDir') ], $app->config('composer')));
				},
				'db' => function($app) {
					$driver = $app->config('dbDriver') ?: 'mysql';
					$host = $app->config('dbHost') ?: 'localhost';
					return new Db($driver . ':host=' . $host . ';dbname=' . $app->config('dbName'), $app->config('dbUser'), $app->config('dbPass'));
				},
			], $this->services);
			//sync composer
			$this->composer->sync();
			//start buffer
			ob_start();
			//create closure
			$moduleFn = function($file) use($app) {
				include_once($file);
			};
			//loop through modules
			foreach(glob($this->config('modulesDir') . '/*', GLOB_ONLYDIR) as $dir) {
				//set vars
				$app = $this;
				$name = basename($dir);
				//skip module?
				if(in_array($name, $this->config['modulesDisabled'])) {
					continue;
				}
				//mark loading
				$this->config['moduleLoading'] = $name;
				//remember module
				if($this->config['theme'] === $name) {
					array_unshift($this->config['modules'], $name);
				} else {
					$this->config['modules'][] = $name;
				}
				//add vendor dir?
				if(is_dir($dir . '/vendor')) {
					array_unshift($this->config['vendorDirs'], $dir . '/vendor');
				}
				//bootstrap module?
				if(is_file($dir . '/module.php')) {
					$moduleFn($dir . '/module.php');
				}
				//cancel loading
				$this->config['moduleLoading'] = '';
			}
			//init event
			$this->event('app.init');
			//upgrade event?
			if($newV = $this->config('version')) {
				//get cached version
				$oldV = $this->cache('version');
				//sync global db schemas
				foreach(glob($this->config('schemasDir') . '/*.sql') as $file) {
					$this->loadSchema($file);
				}
				//new version found?
				if(!$oldV || $newV > $oldV) {
					$this->event('app.upgrade', [ 'from' => $oldV, 'to' => $newV ]);
					$this->cache('version', $newV);
				}
			}
		}

		public function __destruct() {
			//auto-run?
			if($this->config('autoRun')) {
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

		public function __call($name, array $args=[]) {
			//helper exists?
			if(!isset($this->helpers[$name])) {
				throw new \Exception("$name method or helper does not exist");
			}
			//return
			return call_user_func_array($this->helpers[$name], $args);
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

		public function service($name, $obj='%%null%%') {
			//set service?
			if($obj !== '%%null%%') {
				$this->services[$name] = $obj;
				return $obj;
			}
			//has service?
			if(!isset($this->services[$name])) {
				return null;
			}
			//execute closure?
			if($this->services[$name] instanceof \Closure) {
				$this->services[$name] = call_user_func($this->services[$name], $this);
			}
			//get service
			return $this->services[$name];
		}

		public function helper($name, $fn='%%null%%') {
			//set helper?
			if($fn !== '%%null%%') {
				$this->helpers[$name] = $fn;
			}
			//get helper
			return isset($this->helpers[$name]) ? $this->helpers[$name] : null;
		}

		public function event($name, $params='%%null%%', $remove=false) {
			//set array?
			if(!isset($this->events[$name])) {
				$this->events[$name] = [];
			}
			//add/remove event?
			if(is_callable($params)) {
				//generate key
				$key = sha1(serialize(spl_object_hash((object) $params)));
				//add event?
				if(!$remove) {
					$this->events[$name][$key] = $params;
				}
				//remove event?
				if($remove && isset($this->events[$name][$key])) {
					unset($this->events[$name][$key]);
				}
				//return
				return true;
			}
			//execute event
			foreach($this->events[$name] as $fn) {
				//set vars
				$arr = [];
				//add params?
				if($params !== '%%null%%') {
					$arr[] = $params;
				}
				//add app
				$arr[] = $this;
				//call function
				$res = call_user_func_array($fn, $arr);
				//stop here?
				if($res === false) {
					break;
				}
				//update params?
				if($res !== null) {
					$params = $res;
				}
			}
			//return
			return $params;
		}

		public function route($name, $methods, $callable=null) {
			//set vars
			$module = '';
			$name = trim($name, '/') ?: '';
			//is callable?
			if(is_callable($methods)) {
				$callable = $methods;
				$methods = [];
			}
			//methods to array?
			if(!is_array($methods)) {
				$methods = $methods ? explode('|', $methods) : [];
			}
			//add route
			$this->routes[$name] = [
				'methods' => array_map('strtoupper', $methods),
				'module' => $this->config('moduleLoading'),
				'fn' => $callable,
			];
			//return
			return true;
		}

		public function cache($path, $data='%%null%%', $append=false) {
			//set vars
			$output = false;
			$closure = ($data instanceof \Closure);
			//add path?
			if(strpos($path, '/') === false) {
				$path = $this->config('cacheDir') . '/' . $path;
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
					$data = json_encode($data);
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
				$output = call_user_func($data, $output);
			}
			//return
			return $output;
		}

		public function log($name, $data='%%null%%') {
			return $this->cache($this->config('logsDir') . "/{$name}.log", $data, true);	
		}

		public function input($key, $clean='html') {
			//compound key?
			if(strpos($key, '.') !== false) {
				list($global, $key) = explode('.', $key, 2);
			} else {
				$global = $_SERVER['REQUEST_METHOD'];
			}
			//format global
			$global = '_' . strtoupper($global ?: $_SERVER['REQUEST_METHOD']);
			//get value
			$value = isset($GLOBALS[$global]) && isset($GLOBALS[$global][$key]) ? $GLOBALS[$global][$key] : null;
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

		public function tpl($name, array $data=[]) {
			//set defaults
			$data = array_merge([
				'js' => [],
				'meta' => [],
			], $data);
			//loop through default config vars
			foreach([ 'baseUrl', 'env', 'app', 'route.path' ] as $param) {
				//get value
				$val = $this->config($param);
				//set value?
				if($val !== null) {
					$data['js'][explode('.', $param)[0]] = $val;
				}
			}
			//has noindex?
			if(!isset($data['meta']['noindex']) && $this->config('env') !== 'prod') {
				$data['meta']['noindex'] = true;
			}
			//buffer
			ob_start();
			//load view
			$view = new View($this);
			$view->tpl($name, $data, true);
			//get html;
			$html = ob_get_clean();
			//add js vars?
			if($data['js']) {
				$clean = $this->clean($data['js']);
				$js = '<script>window.pageData = ' . json_encode($clean) . ';</script>';
				$html = str_replace('</head>', $js . "\n" . '</head>', $html);
			}
			//return
			echo $html;
		}

		public function path($path='', array $opts=[]) {
			//set vars
			$baseDir = $this->config('baseDir');
			$modulesDir = $this->config('modulesDir');
			$checkPaths = array_merge($this->config('modules'), [ $baseDir ]);
			$checkExts = [ 'tpl' => 'tpl', 'css' => 'css', 'js' => 'js', 'png' => 'img', 'jpg' => 'img', 'jpeg' => 'img', 'gif' => 'img' ];
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
				$tmp = $path;
				$path = $this->config('baseUrl') . $path;
				//add timestamp?
				if($opts['time'] && strpos($tmp, '.') !== false) {
					//get file
					$file = $this->config('baseDir') . '/' . $tmp;
					//file exists?
					if(is_file($file)) {
						$path .= (strpos($path, '?') !== false ? '&' : '?') . filemtime($file);
					}
				}
			}
			//return
			return $this->clean($path, $opts['clean']);
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

		public function schedule($name, $fn='%%null%%', $interval=3600, $reset=false) {
			//set vars
			$next = null;
			$update = false;
			$app = $this;
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
				$this->cron[$next] = [ 'name' => $name, 'interval' => $interval, 'fn' => $fn ];
				ksort($this->cron);
			}
			//stop here?
			if(!$next || !isset($this->cron[$next])) {
				return null;
			}
			//get function
			$fn = $this->cron[$next]['fn'];
			//return function
			return function() use($fn, $app) {
				return call_user_func($fn, $app);
			};
		}

		public function cron($job=null) {
			//set vars
			$limit = 300;
			$jobs = $this->cache('cron') ?: [];
			$isRunning = $this->cache('cron-running');
			$next = $this->cron ? array_keys($this->cron)[0] : 0;
			//is cmd line?
			if(!$this->_hasRun && !$job) {
				$this->config('webCron', false);
				$this->config('router', false);
			}
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
				//check web cron?
				if($this->config('webCron') && !isset($_GET['cron'])) {
					//call now?
					if($next <= time() && !$isRunning) {
						$url = $this->config('baseUrl') . '?cron=' . time();
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
				//valid time?
				if(!$job && $time > time()) {
					break;
				}
				//call function
				try {
					call_user_func($meta['fn'], $this);
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
			//use web cron?
			if($this->config('webCron')) {
				$this->cron();
			}
			//use router?
			if($this->routes && $this->config('router') && !isset($_GET['cron']) && !ob_get_contents()) {
				//set vars
				$found = false;
				//search for route
				foreach([ $this->config('pathInfo'), '404' ] as $needle) {
					//exact match?
					if(isset($this->routes[$needle])) {
						$tmp = $this->routes[$needle];
						unset($this->routes[$needle]);
						$this->routes = [ $needle => $tmp ] + $this->routes;
					}
					//loop through routes
					foreach($this->routes as $path => $meta) {
						//valid method?
						if($meta['methods'] && !in_array($_SERVER['REQUEST_METHOD'], $meta['methods'])) {
							continue;
						}
						//valid route?
						if(!preg_match('/^' . str_replace([ '/', '\\\\' ], [ '\/', '\\' ], $path) . '$/', $needle, $params)) {
							continue;
						}
						//format params
						array_shift($params);
						//format route
						$meta['path'] = $path;
						$meta['params'] = array_map(function($v) { return str_replace('/', '', $v); }, $params);
						//filter route?
						if($meta = $this->event('app.route', $meta)) {
							//update flag
							$found = true;
							//update config
							$this->config('route', (object) $meta);
							//call route
							call_user_func($meta['fn'], $this);
							//stop
							break 2;
						}
					}
				}
				//nothing found?
				if($found === false) {
					header("HTTP/1.0 404 Not Found");
					echo 'Page not found';
				}
			}
			//get final output
			$output = trim(ob_get_clean());
			//add debug bar?
			if($this->config('env') === 'dev') {
				$output = str_replace('</body>', $this->debug() . "\n" . '</body>', $output);
			}
			//filter output?
			if($output = $this->event('app.output', $output)) {
				echo $output;
			}
			//shutdown event
			$this->event('app.shutdown');
		}

		public function debug() {
			//debug vars
			$queries = [];
			$time = number_format(microtime(true) - $this->_startTime, 5);
			$mem = number_format((memory_get_usage() - $this->_startMem) / 1024, 0);
			$peak = number_format(memory_get_peak_usage() / 1024, 0);
			//load queries?
			if(isset($this->services['db']) && !($this->services['db'] instanceOf \Closure)) {
				$queries = $this->db->getLog();
			}
			//debug data
			$debug  = '<div id="debug-bar" style="width:100%; font-size:12px; text-align:left; padding:10px; margin-top:20px; background:#eee; position:fixed; bottom:0;">' . "\n";
			$debug .= '<div><b>Debug:</b> Time: ' . $time . 's | Mem: ' . $mem . 'kb | Peak: ' . $peak . 'kb | Queries: ' . count($queries) . '</div>' . "\n";
			//db queries?
			if($queries) {
				$debug .= '<ol style="margin:10px 0 0 0; padding-left:15px;">' . "\n";
				foreach($queries as $q) {
					$debug .= '<li style="margin-top:3px;">' . $q . '</li>' . "\n";
				}
				$debug .= '</ol>' . "\n";
			}
			$debug .= '</div>' . "\n";
			//return
			return $debug;
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
				'display' => $display,
			];
			//skip error?
			if(!$error = $this->event('app.error', $error)) {
				return;
			}
			//meta data
			$meta = $error['type'] . " | " . str_replace($this->config('baseDir') . '/', '', $error['file']) . " | line " . $error['line'];
			//log error
			$this->log('errors', "[" . $error['date'] . "]\n" . $meta . "\n" . $error['message'] . "\n");
			//display error?
			if($error['display'] && $this->config('env') === 'dev') {
				echo '<div class="error" style="margin:1em 0; padding: 0.5em; border:1px red solid;">' . $meta . '<br><br>' . $error['message'] . '</div>' . "\n";
			}
		}

	}

}