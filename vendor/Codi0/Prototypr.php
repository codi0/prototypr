<?php

namespace Codi0;

class Prototypr {

	private $config;
	private $helpers = [];
	private $services = [];

	private $cron = [];
	private $routes = [];
	private $run = false;

	public static function singleton(array $opts=[]) {
		static $obj = null;
		//create object?
		if($obj === null) {
			$cls = __CLASS__;
			$obj = new $cls($opts);
		}
		//return
		return $obj;
	}

	public function __construct(array $opts=[]) {
		//set vars
		$app = $this;
		$reqUri = explode('?', $_SERVER['REQUEST_URI'])[0];
		$basePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname($_SERVER['SCRIPT_FILENAME']));
		//loop through opts
		foreach($opts as $k => $v) {
			if(property_exists($this, $k)) {
				$this->$k = $v;
			}
		}
		//set config object
		$this->config = (object) ($this->config ?: null);
		//set dev mode?
		if(!isset($this->config->isDev)) {
			$this->config->isDev = true;
		}
		//set base dir?
		if(!isset($this->config->baseDir)) {
			$this->config->baseDir = dirname(get_included_files()[0]);
		}
		//error reporting
		error_reporting(E_ALL);
		ini_set('display_errors', $this->config->isDev ? 1 : 0);
		ini_set('display_startup_errors', $this->config->isDev ? 1 : 0);
		//log errors
		ini_set('log_errors', 1);
		ini_set('error_log', $this->config->baseDir . '/logs/error.log');
		//set path info
		$this->config->pathInfo = $_SERVER['PATH_INFO'] = trim(str_replace($basePath, '', $reqUri), '/');
		//set host
		$this->config->ssl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? ($_SERVER['HTTPS'] !== 'off') : ($_SERVER['SERVER_PORT'] === 443);
		$this->config->host = 'http' . ($this->config->ssl ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];
		//set base url?
		if(!isset($this->config->baseUrl)) {
			$this->config->baseUrl = $this->config->host . '/' . trim($basePath, '/') . '/';
		}
		//file loader
		spl_autoload_register(function($class) use($app) {
			//format path
			$path = $app->config->baseDir . '/vendor/' . str_replace('\\', '/', $class) . '.php';
			//file exists?
			if(file_exists($path)) {
				require($path);
			}
		});
		//default services
		$this->services = array_merge([
			'db' => function($app) {
				$host = isset($app->config->dbHost) ? $app->config->dbHost : 'localhost';
				return new \PDO('mysql:host=' . $host . ';dbname=' . $app->config->dbName, $app->config->dbUser, $app->config->dbPass);
			}
		], $this->services);
		//start buffer
		ob_start();
	}

	public function __destruct() {
		//auto-run
		$this->run();
	}

	public function __get($key) {
		//is config?
		if($key === 'config') {
			return $this->config;
		}
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

	public function service($name, $obj='%%null%%') {
		//delete service?
		if($obj === null) {
			if(isset($this->services[$name])) {
				unset($this->services[$name]);
			}
			return true;
		}
		//set service?
		if($obj !== '%%null%%') {
			$this->services[$name] = $obj;
			return true;
		}
		//has service?
		if(!isset($this->services[$name])) {
			return null;
		}
		//execute closure?
		if($this->services[$name] instanceof \Closure) {
			$this->services[$name] = call_user_func($this->services[$name], $this);
		}
		//return
		return $this->services[$name];
	}

	public function helper($name, $fn) {
		//is valid callable?
		if(!is_callable($fn)) {
			throw new \Exception("Helper $name is not callable");
		}
		//set helper
		$this->helpers[$name] = $fn;
		//return
		return true;
	}

	public function route($name, $callable) {
		//format name
		$name = trim($name, '/') ?: '';
		//set callable
		$this->routes[$name] = $callable;
		//return
		return true;
	}

	public function cache($name, $data='%%null%%', $append=false) {
		//get cache path
		$path = $this->config->baseDir . '/cache/' . $name . '.json';
		//delete data?
		if($data === null) {
			return @unlink($path);
		}
		//set data?
		if($data !== '%%null%%') {
			//encode data?
			if(!is_string($data) && !is_numeric($data)) {
				$data = json_encode($data);
			}
			//append data?
			if($append) {
				$data  = trim($data) . "\n";
			}
			//save to file
			return file_put_contents($path, $data, $append ? FILE_APPEND : null);
		}
		//has data?
		if(($data = @file_get_contents($path)) === false) {
			return null;
		}
		//return
		return @json_decode($data, true) ?: $data;
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
				$value[$k] = $this->clean($v, $context);
			}
			//return
			return $value;
		}
		//is string?
		if(is_string($value) || is_numeric($value)) {
			//is html?
			if($context === 'html') {
				return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8', true);
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
		}
		//unprocessed
		return $value;
	}

	public function template($name, array $data=[]) {
		//set app
		$app = $this;
		//create view
		$tpl = new class {
			private $data = [];
			public function block($name, array $data=[]) {
				global $app;
				//build path
				$path = $app->config->baseDir . '/templates/' . $name . '.php';
				//file exists?
				if(!is_file($path)) {
					throw new \Exception("Template $name not found");
				}
				//merge data
				$this->data = array_merge($this->data, $data);
				//view closure
				$fn = function($__path, $tpl) {
					include($__path);
				};
				//load view
				$fn($path, $this);
			}
			public function data($key, $clean='html') {
				global $app;
				//set vars
				$data = $this->data;
				$parts = explode('.', $key);
				//loop through parts
				foreach($parts as $i => $part) {
					//is config?
					if(!$i && $part === 'config') {
						$data = $app->config;
						continue;
					}
					//data exists?
					if(is_object($data)) {
						if(isset($data->$part)) {
							$data = $data->$part;
						} else {
							$data = null;
							break;
						}
					} else {
						if(isset($data[$part])) {
							$data = $data[$part];
						} else {
							$data = null;
							break;
						}
					}
				}
				//clean data?
				if($data) {
					$data = $app->clean($data, $clean);
				}
				//return
				return ($data || $data == '0') ? $data : '';
			}
			public function clean($value, $clean='html') {
				global $app;
				return $app->clean($value, $clean);
			}
			public function url($path, $clean='') {
				global $app;
				return $app->clean($app->config->baseUrl . $path, $clean);
			}
		};
		//return
		return $tpl->block($name, $data);
	}

	public function cron($name, $fn, $interval, $reset=false) {
		//set vars
		$next = null;
		$update = false;
		$jobs = $this->cache('cronJobs') ?: [];
		//loop though jobs
		foreach($jobs as $k => $v) {
			if($v['name'] === $name) {
				$next = $k;
				break;
			}
		}
		//delete job?
		if($next && ($reset || !$fn)) {
			unset($jobs[$next], $this->cron[$next]);
			$update = true;
			$next = null;
		}
		//add job?
		if(!$next && $fn) {
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
			$this->cache('cronJobs', $jobs);
		}
		//save callback?
		if($fn) {
			$this->cron[$next] = [ 'name' => $name, 'interval' => $interval, 'fn' => $fn ];
			ksort($this->cron);
		}
	}

	public function run() {
		//has run?
		if($this->run) {
			return;
		}
		//update flag
		$this->run = true;
		//get output
		$output = trim(ob_get_clean());
		//has cron?
		if($this->cron) {
			//check time
			$next = array_keys($this->cron)[0];
			$isRunning = $this->cache('cronRunning');
			//reset cron?
			if($isRunning && $isRunning < (time() - 300)) {
				$isRunning = null;
				$this->cache('cronRunning', null);
			}
			//call cron?
			if(!isset($_GET['cron']) && $next <= time() && !$isRunning) {
				//cron vars
				$timeout = 3;
				$parse = parse_url($this->config->baseUrl . '?cron=' . time());
				$scheme = ($parse['scheme'] === 'https') ? 'ssl' : 'tcp';
				$port = ($parse['scheme'] === 'https') ? 443 : 80;
				//successful connection?
				if($fp = @fsockopen($scheme . '://' . $parse['host'], $port, $errno, $errstr, $timeout)) {
					//set stream options
					@stream_set_blocking($fp, 0);
					@stream_set_timeout($fp, $timeout);
					//set request headers
					$request  = "GET " . (isset($parse['path']) ? $parse['path'] : '/') . (isset($parse['query']) ? '?' . $parse['query'] : '') . " HTTP/1.0\r\n";
					$request .= "Host: " . $parse['host'] . "\r\n";
					$request .= "Connection: Close\r\n\r\n";			
					//send request
					@fputs($fp, $request, strlen($request));
					//clear buffer
					@fread($fp, 1024);
					//close
					@fclose($fp);
				}
			}
			//execute cron?
			if(isset($_GET['cron']) && $next <= time() && !$isRunning) {
				//let run
				set_time_limit(300);
				ignore_user_abort(true);
				//lock cron
				$this->cache('cronRunning', time());
				//get jobs cache
				$jobs = $this->cache('cronJobs') ?: [];
				//loop through jobs
				foreach($this->cron as $time => $meta) {
					//stop here?
					if($time > time()) {
						break;
					}
					//call function
					call_user_func($meta['fn'], $this);
					//reset job?
					if(isset($jobs[$time])) {
						$this->cron($meta['name'], $meta['fn'], $meta['interval'], true);
					}
				}
				//release cron
				$this->cache('cronRunning', null);
			}
		}
		//has output?
		if($output) {
			echo $output;
			return;
		}
		//has route?
		if($this->routes) {
			//route found?
			if(isset($this->routes[$this->config->pathInfo])) {
				call_user_func($this->routes[$this->config->pathInfo], $this);
			} else if(isset($this->routes['404'])) {
				call_user_func($this->routes['404'], $this);
			} else {
				//default 404
				header("HTTP/1.0 404 Not Found");
				echo 'Page not found';
			}
		}
	}

}