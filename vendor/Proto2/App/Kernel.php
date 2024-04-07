<?php

namespace {

	//create wrapper
	function Proto2($opts=[]) {
		return \Proto2\App\Kernel::factory($opts);
	}

}

namespace Proto2\App {

	class Kernel {

		private $_opts = [];
		private $_run = false;
		private $_registry = null;
	
		private static $_instances = [];

		public static function factory($config=[]) {
			//cache last instance
			static $last = 'app';
			//format config?
			if(!is_array($config)) {
				$config = [ 'instance' => $config ?: $last ];
			} else if(!isset($config['instance']) || !$config['instance']) {
				$config['instance'] = $last;
			}
			//cache instance name
			$last = $i = $config['instance'];
			//create instance?
			if(!isset(self::$_instances[$i])) {
				self::$_instances[$i] = new self($config);
				self::$_instances[$i]->setup();
			}
			//return instance
			return self::$_instances[$i];		
		}

		public function __construct(array $opts=[]) {
			//set opts
			$this->_opts = array_merge([
				'instance' => 'app',
				'paths' => [],
			], $opts);
			//set default paths
			$this->_opts['paths'] = array_merge([
				'script' => dirname($_SERVER['SCRIPT_FILENAME']),
				'base' => explode('/vendor/', str_replace('\\', '/', dirname(array_reverse(get_included_files())[1])))[0],
			], $this->_opts['paths']);
			//standardise paths format
			foreach($this->_opts['paths'] as $k => $v) {
				$this->_opts['paths'][$k] = str_replace('\\', '/', $v);
			}
		}

		public function __isset($key) {
			return $this->_registry->has($key);
		}

		public function __get($key) {
			return $this->_registry->get($key);
		}

		public function __set($key, $val) {
			throw new \Exception("Properties cannot be set on the kernel");
		}

		public function setup() {
			//has run?
			if($this->_registry) {
				return $this;
			}
			//default config
			$config = $this->_defaultConfig($this->_opts);
			//use class autoloader?
			if($config['autoloader']) {
				spl_autoload_register($config['autoloader']);
			}
			//create config object
			$config = new $config['registry']['config']['class']($config, $this->_opts);
			//merge core config files
			$this->loadConfig($config->get('paths.config'), $config);
			//create registry object
			$registry = $config->get('registry.registry.class');
			$registry = $this->_registry = new $registry;
			//add objects to registry
			$registry->set('kernel', $this);
			$registry->set('config', $config);
			$registry->set('registry', $registry);
			//create facade?
			if($facade = $config->get('facade')) {
				$this->helpers->facade($facade, $this);
			}
			//setup core paths
			foreach($config->get('paths') as $path) {
				//is array?
				if(is_array($path)) {
					$path = $path ? $path[0] : '';
				}
				//make path?
				if($path && !is_dir($path)) {
					mkdir($path, 0755);
				}
			}
			//registry autoload
			foreach($config->get('registry') as $k => $v) {
				//can autoload?
				if(isset($v['autoload']) && $v['autoload']) {
					//get object
					$o = $registry->get($k);
					//call method?
					if($o && is_string($v['autoload'])) {
						$o->{$v['autoload']}();
					}
				}
			}
			//module params
			$modulesDir = $config->get('paths.modules') ?: '';
			$modulesWl = $config->get('modules') ?: [];
			//reset modules
			$config->set('modules', []);
			//autoload modules
			foreach(glob($modulesDir . '/*', GLOB_ONLYDIR) as $dir) {
				//set vars
				$match = [];
				$name = basename($dir);
				//whitelist match?
				if($modulesWl && !isset($modulesWl[$name]) && !in_array($name, $modulesWl)) {
					continue;
				}
				//has env matches?
				if(isset($modulesWl[$name]) && is_array($modulesWl[$name])) {
					//loop through array
					foreach($modulesWl[$name] as $k => $v) {
						if($k && $v) {
							$match[] = $k . '=' . $v;
						}
					}
				}
				//can load module?
				if(!$match || $this->matchEnv($match)) {
					$this->loadModule($name);
				}
			}
			//get event manager
			$eventManager = $registry->get('eventManager');
			//init event
			$eventManager->dispatch('kernel.init', [
				'kernel' => $this,
			]);
			//update event?
			if($newV = $config->get('version')) {
				//get cache object
				$cache = $registry->get('cache');
				//get cached version
				$oldV = $cache->get('version');
				//new version found?
				if(!$oldV || $newV > $oldV) {
					//dispatch update event
					$eventManager->dispatch('kernel.update', [
						'oldVersion' => $oldV,
						'newVersion' => $newV,
					]);
					//update cache
					$cache->set('version', $newV);
				}
			}
			//get autorun
			if(($autorun = $config->get('autorun')) === null) {
				$autorun = !$config->get('included');
			}
			//run now?
			if($autorun) {
				return $this->run();
			}
			//chain it
			return $this;
		}

		public function run($callback = null) {
			//has run?
			if($this->_run) {
				return $this;
			}
			//update flag
			$this->_run = true;
			//run setup?
			if(!$this->_registry) {
				$this->setup();
			}
			//run event
			$e = $this->eventManager->dispatch('kernel.run', [
				'kernel' => $this,
				'server' => function() {
					return $this->httpKernel->run()->send();
				},
			]);
			//run now?
			if($e->server) {
				call_user_func($e->server);
			}
			//terminate event
			$this->eventManager->dispatch('kernel.terminate', [
				'kernel' => $this,
			]);
			//chain it
			return $this;
		}

		public function runCli($filePath, array $args=[], $content='') {
			//create php process
			return $this->process->run($filePath, $args, $content);
		}

		public function runServer($host, $port = null, $callback = null) {
			//port is callback?
			if(is_callable($port)) {
				$callback = $port;
				$port = null;
			}
			//parse host?
			if(is_numeric($host)) {
				$port = $host;
				$host = 'localhost';
			} else if(strpos($host, ':') !== false) {
				list($host, $port) = explode(':', $host, 2);
			}
			//server event
			$e = $this->eventManager->dispatch('kernel.server', [
				'kernel' => $this,
				'server' => function($host, $port, $callback) {
					return $this->httpServer->create($host, $port, $callback);
				},
			]);
			//run now?
			if($e->server) {
				return call_user_func($e->server, $host, $port, $callback);
			}
		}

		public function matchEnv($args) {
			//set vars
			$platform = '';
			$hosts = $this->config->get('hosts');
			$domain = $this->config->get('urls.domain');
			$tags = (array) (isset($hosts[$domain]) ? $hosts[$domain] : []);
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
				//skip arg?
				if(!$v) continue;
				//check for match
				if($k === 'tags') {
					//values as array
					$vArr = array_map('trim', explode(',', $v));
					//loop through array
					foreach($vArr as $t) {
						//tag found?
						if($t && !in_array($t, $tags)) {
							return false;
						}
					}
				} else if($k === 'function') {
					//function found?
					if(!function_exists($v)) {
						return false;
					}
				} else if($k === 'pathinfo') {
					//path found?
					if(!preg_match('#' . $v . '#', $_SERVER['PATH_INFO'])) {
						return false;
					}
				} else if($k === 'platform') {
					//check platform?
					if(empty($platform)) {
						$platform = $this->platform->getVar('loaded');
					}
					//match found?
					if($platform !== $v) {
						return false;
					}
				}
			}
			//return
			return true;
		}

		public function loadConfig($dir, $config=null) {
			//set vars
			$envFiles = [];
			$config = $config ?: $this->config;
			//load core config
			foreach(glob($dir . '/*.*') as $file) {
				//remove extension
				$name = pathinfo($file, PATHINFO_FILENAME);
				//has env?
				if(strpos($name, '.') === false) {
					$config->mergeFile($file);
				} else {
					$envFiles[$name] = $file;
				}
			}
			//normalize env?
			if($config && !$this->_registry) {
				$this->_normalizeEnv($config);
			}
			//get env
			$env = $config->get('env');
			//load env config
			foreach($envFiles as $name => $file) {
				//env match?
				if($env === pathinfo($name, PATHINFO_EXTENSION)) {
					$config->mergeFile($file);
				}
			}
			//success
			return true;
		}

		public function loadModule($name) {
			//set vars
			$config = $this->config;
			$module = $config->get("modules.$name");
			//cache miss?
			if($module === null) {
				//module dir
				$moduleDir = $config->get('paths.modules') . '/' . $name;
				//load config
				$this->loadConfig($moduleDir . '/config');
				//set module paths
				$moduleBootFile = $moduleDir . '/module.php';
				$moduleVendorDir = $moduleDir . '/vendor';
				//add vendor?
				if(is_dir($moduleVendorDir)) {
					//prepend to paths config
					$config->set('paths.vendor', function($vendor) use($moduleVendorDir) {
						//add to array?
						if(!in_array($moduleVendorDir, $vendor)) {
							array_unshift($vendor, $moduleVendorDir);
						}
						//return
						return $vendor;
					});
				}
				//add to config
				$config->set("modules.$name", false);
				//boot module?
				if(is_file($moduleBootFile)) {
					//load now
					$module = (function($__file) {
						return require_once($__file);
					})($moduleBootFile);
				}
				//update config?
				if($module) {
					$config->set("modules.$name", $module);
				}
			}
			//return
			return $module;
		}

		public function loadClass($class, $sep='\\') {
			//set vars
			$paths = [];
			$class = trim($class, $sep);
			//check config?
			if($registry = $this->_registry) {
				//get config object
				$config = $registry->get('config');
				//set paths
				$paths = $config->get('paths.vendor');
				//check class map?
				if($map = $config->get('classes')) {
					//cache class
					$tmp = $class;
					//start loop
					while($tmp) {
						//match found?
						if(isset($map[$tmp])) {
							$paths = [ $map[$tmp] ];
							$class = trim(str_replace($tmp, '', $class), $sep);
							break;
						}
						//get last occurence
						$pos = strrpos($tmp, $sep);
						//stop here?
						if($pos === false) {
							break;
						}
						//remove last segment
						$tmp = substr($tmp, 0, $pos);
					}
				}
			}
			//default path?
			if(!$paths) {
				$dir = str_replace('\\', '/', __DIR__);
				$paths = [ explode('/vendor/', $dir)[0] . '/vendor' ];
			}
			//loop through paths
			foreach($paths as $path) {
				//format path
				$path = $path . '/' . $class . '.php';
				$path = str_replace($sep, '/', $path);
				//file exists?
				if(is_file($path)) {
					//load file
					require_once($path);
					//success
					return true;
				}
			}
			//not found
			return false;
		}

		public function registerClass($class, $file) {
			//set vars
			$class = trim($class, '\\');
			$file = rtrim($file, '/');
			//add to config
			return $this->config->set("classes.$class", $file);
		}

		protected function _defaultConfig(array $opts) {
			return [
				//core
				'instance' => $opts['instance'],
				'facade' => ucfirst($opts['instance']),
				'cli' => (php_sapi_name() === 'cli' || defined('STDIN')),
				'included' => $opts['paths']['base'] !== $opts['paths']['script'],
				'env' => 'dev',
				'env_opts' => [ 'dev', 'staging', 'prod' ],
				'debug' => null,
				'debug_bar' => true,
				'autoloader' => array_key_exists('autoloader', $opts) ? $opts['autoloader'] : [ $this, 'loadClass' ],
				'autorun' => null,
				'theme' => 'theme',
				//hosts
				'hosts' => [],
				//urls
				'urls' => [
					'base' => '',
					'current' => '',
					'domain' => '',
				],
				//paths
				'paths' => [
					'script' => $opts['paths']['script'],
					'base' => $opts['paths']['base'],
					'cache' => $opts['paths']['base'] . '/cache',
					'config' => $opts['paths']['base'] . '/config',
					'logs' => $opts['paths']['base'] . '/logs',
					'modules' => $opts['paths']['base'] . '/modules',
					'vendor' => [ $opts['paths']['base'] . '/vendor' ],
				],
				//classes
				'classes' => [],
				//modules
				'modules' => [],
				//registry
				'registry' => [
					'apiKernel' => [
						'class' => 'Proto2\Api\Kernel',
						'opts' => [
							'baseUrl' => '%urls.base%',
							'router' => '[router]',
							'eventManager' => '[eventManager]',
						],
					],
					'apiRoute' => [
						'class' => 'Proto2\Api\Route',
						'opts' => [
							'baseUrl' => '%urls.base%',
							'input' => '[input]',
							'validator' => '[validator]',
						],
					],
					'apiUi' => [
						'class' => 'Proto2\Api\Ui',
						'opts' => [
							'input' => '[input]',
							'cache' => '[cache]',
							'helpers' => '[helpers]',
							'httpClient' => '[httpClient]',
							'eventManager' => '[eventManager]',
						],
					],
					'cache' => [
						'class' => 'Proto2\Cache\File',
						'opts' => [
							'dir' => '%paths.cache%',
						],
					],
					'captcha' => [
						'class' => 'Proto2\Security\Captcha',
						'opts' => [
							'crypt' => '[crypt]',
							'session' => '[httpSession]',
						],
					],
					'chatGpt' => [
						'class' => 'Proto2\Helper\ChatGpt',
					],
					'composer' => [
						'class' => 'Proto2\App\Composer',
						'autoload' => 'sync',
						'opts' => [
							'baseDir' => '%paths.base%',
						],
					],
					'config' => [
						'class' => isset($opts['registry']['config']['class']) ? $opts['registry']['config']['class'] : 'Proto2\App\Config',
					],
					'crypt' => [
						'class' => 'Proto2\Security\Crypt',
						'opts' => [
							'config' => '[config]',
						],
					],
					'csrf' => [
						'class' => 'Proto2\Security\Csrf',
						'opts' => [
							'crypt' => '[crypt]',
							'session' => '[session]',
						],
					],
					'db' => [
						'class' => 'Proto2\Db\Db',
					],
					'docGenerator' => [
						'class' => 'Proto2\Helper\DocGenerator',
					],
					'dom' => [
						'class' => 'Proto2\Html\Dom',
					],
					'errorHandler' => [
						'class' => 'Proto2\Debug\ErrorHandler',
						'autoload' => 'handle',
						'opts' => [
							'cli' => '%cli%',
							'debug' => '%debug%',
							'debugBar' => '%debug_bar%',
							'db' => '[db]',
							'logger' => '[logger]',
							'eventManager' => '[eventManager]',
							'displayCallback' => function($errMsg, $ex) {
								if($this->router && $this->router->has('500')) {
									return $this->router->call('500', [ $errMsg, $ex ]);
								}
							},
						],
					],
					'escaper' => [
						'class' => 'Proto2\Security\Escaper',
					],
					'eventManager' => [
						'class' => 'Proto2\Event\Manager',
					],
					'googleCse' => [
						'class' => 'Proto2\Helper\GoogleCse',
					],
					'helpers' => [
						'class' => 'Proto2\App\Helpers',
						'args' => [
							'context' => $this,
						],
					],
					'htmlField' => [
						'class' => 'Proto2\Html\Field',
						'opts' => [
							'captcha' => '[captcha]',
							'helpers' => '[helpers]',
						],
					],
					'htmlForm' => [
						'class' => 'Proto2\Html\Form',
						'opts' => [
							'input' => '[input]',
							'validator' => '[validator]',
							'htmlField' => '[htmlField]',
						],
					],
					'htmlTable' => [
						'class' => 'Proto2\Html\Table',
						'opts' => [
							'component' => '[htmlComponent]',
							'eventManager' => '[eventManager]',
						],
					],
					'httpClient' => [
						'class' => 'Proto2\Http\Client',
						'opts' => [
							'eventManager' => '[eventManager]',
						],
					],
					'httpCookie' => [
						'class' => 'Proto2\Http\Cookie',
						'opts' => [
							'crypt' => '[crypt]',
						],
					],
					'httpFactory' => [
						'class' => 'Proto2\Http\Factory',
					],
					'httpKernel' => [
						'class' => 'Proto2\Http\Kernel',
						'opts' => [
							'router' => '[router]',
							'middleware' => [
								'before' => [],
								'after' => [
									'[apiKernel]',
								],
							],
						],
					],
					'httpServer' => [
						'class' => 'Proto2\Async\Server',
						'opts' => [
							'httpKernel' => '[httpKernel]',
						],
					],
					'httpSession' => [
						'class' => 'Proto2\Http\Session',
						'opts' => [
							'cookie' => '[httpCookie]',
						],
					],
					'input' => [
						'class' => 'Proto2\Http\Input',
						'opts' => [
							'validator' => '[validator]',
						],
					],
					'jwt' => [
						'class' => 'Proto2\Security\Jwt',
						'opts' => [
							'crypt' => '[crypt]',
						],
					],
					'kernal' => [
						'class' => get_class($this),
					],
					'logger' => [
						'class' => 'Proto2\Debug\Logger',
						'opts' => [
							'dir' => '%paths.logs%',
							'defaultChannel' => 'errors',
						],
					],
					'mail' => [
						'class' => 'Proto2\Mail\Mail',
						'opts' => [
							'fromName' => '%mail.from.name%',
							'fromEmail' => '%mail.from.email%',
							'eventManager' => '[eventManager]',
						],
					],
					'orm' => [
						'class' => 'Proto2\Orm\Orm',
						'opts' => [
							'namespace' => '%facade%',
							'db' => '[db]',
							'eventManager' => '[eventManager]',
							'validator' => '[validator]',
						],
					],
					'pdfParser' => [
						'class' => 'Proto2\Helper\PdfParser',
					],
					'platform' => [
						'class' => 'Proto2\App\Platform',
						'autoload' => 'run',
					],
					'process' => [
						'class' => 'Proto2\Async\Process',
						'opts' => [
							'baseUrl' => '%urls.base%',
						],
					],
					'proxy' => [
						'class' => 'Proto2\App\Proxy',
						'args' => [
							'target' => null,
							'eventManager' => '[eventManager]',
						],
					],
					'queue' => [
						'class' => 'Proto2\Async\Queue',
					],
					'registry' => [
						'class' => 'Proto2\App\Registry',
					],
					'router' => [
						'class' => 'Proto2\Route\Dispatcher',
						'opts' => [
							'routes' => [
								'404' => function() {
									if($this->view->has('404')) {
										return $this->view->tpl('404');
									} else {
										return '<h1>Oops, page not found</h1>';
									}
								},
								'500' => function() {
									if($this->view->has('500')) {
										return $this->view->tpl('500');
									} else {
										return '<h1>An error has occured</h1>';
									}
								},
							],
						],
					],
					'test' => [
						'class' => 'Proto2\Debug\Test',
						'opts' => [
							'baseDir' => '%paths.base%',
						],
					],
					'validator' => [
						'class' => 'Proto2\Security\Validator',
						'opts' => [
							'db' => '[db]',
							'crypt' => '[crypt]',
							'captcha' => '[captcha]',
						],
					],
					'view' => [
						'class' => 'Proto2\Html\View',
						'opts' => [
							'config' => '[config]',
							'escaper' => '[escaper]',
							'eventManager' => '[eventManager]',
							'helpers' => '[helpers]',
							'router' => '[router]',
						],
					],
				],
			];
		}

		protected function _normalizeEnv($config) {
			//config vars
			$isCli = $config->get('cli');
			$urls = $config->get('urls') ?: [];
			$hosts = $config->get('hosts') ?: [];
			$docRoot = $config->get('paths.base');
			//request vars
			$reqVars = [
				'method' => 'GET',
				'baseUrl' => $urls['base'],
				'route' => isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '',
			];
			//check argv?
			if($isCli) {
				//loop through array
				foreach($_SERVER['argv'] as $arg) {
					//trim arg
					$arg = ltrim($arg, '-');
					//has value?
					if(strpos($arg, "=") > 0) {
						//parse value
						list($k, $v) = array_map('trim', explode('=', $arg, 2));
						//match found?
						if(isset($reqVars[$k])) {
							$reqVars[$k] = trim($v, '/');
						}
					}
				}
			}
			//parse url
			$parse = parse_url($reqVars['baseUrl']) ?: [];
			$parse = array_merge([ 'scheme' => '', 'host' => '', 'path' => '' ], $parse);
			//update doc root?
			if(substr_compare($docRoot, $parse['path'], -strlen($parse['path'])) === 0) {
				$docRoot = substr($docRoot, 0, -strlen($parse['path']));
			}
			//add route to path?
			if($reqVars['route']) {
				$reqVars['route'] = '/' . $reqVars['route'];
				$parse['path'] .= $reqVars['route'];
			}
			//default env
			$envDefaults = [
				'SERVER_PORT' => ($parse['scheme'] === 'https') ? 443 : 80,
				'HTTPS' => ($parse['scheme'] === 'https') ? 'on' : 'off',
				'HTTP_HOST' => $parse['host'],
				'REQUEST_METHOD' => strtoupper($reqVars['method']),
				'REQUEST_URI' => $parse['path'] ?: '/',
				'PATH_INFO' => $reqVars['route'],
				'DOCUMENT_ROOT' => $docRoot,
			];
			//set $_SERVER defaults
			foreach($envDefaults as $k => $v) {
				//is set?
				if(!isset($_SERVER[$k]) || !$_SERVER[$k]) {
					$_SERVER[$k] = $v;
				}
			}
			//build uri components
			$ssl = $_SERVER['HTTPS'] !== 'off';
			$domain = 'http' . ($ssl ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];
			$docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
			$scriptFile = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
			//generate urls
			$generated = [
				'base' => $domain . rtrim(str_replace($docRoot, '', $scriptFile), '/'),
				'current' => $domain . rtrim($_SERVER['REQUEST_URI'], '/'),
				'domain' => $domain,
			];
			//is host allowed?
			if(!$_SERVER['HTTP_HOST'] || ($hosts && !isset($hosts[$_SERVER['HTTP_HOST']]))) {
				if($isCli) {
					echo 'ERROR: valid --baseUrl arg required';
					exit();
				} else {
					http_response_code(400);
					exit();
				}
			}
			//set host env?
			if(isset($hosts[$_SERVER['HTTP_HOST']]) && $hosts[$_SERVER['HTTP_HOST']]) {
				//get tags array
				$tags = (array) $hosts[$_SERVER['HTTP_HOST']];
				//loop through env opts
				foreach($config->get('env_opts') as $e) {
					//match found?
					if(in_array($e, $tags)) {
						$config->set('env', $e);
						break;
					}
				}
			}
			//set debug?
			if($config->get('debug') === null) {
				$config->set('debug', $config->get('env') === 'dev');
			}
			//cache urls
			foreach([ 'base', 'current', 'domain' ] as $key) {
				if(!$urls[$key]) {
					$config->set('urls.' . $key, $generated[$key]);
				}
			}
			//check body?
			if(empty($_POST)) {
				//raw body found?
				if($raw = @file_get_contents('php://input')) {
					//attempt decode
					if(!$tmp = @json_decode($raw, true)) {
						parse_str($raw, $tmp);
					}
					//set array?
					if(!empty($tmp)) {
						$_POST = $tmp;
					}
				}
			}
		}

	}

}