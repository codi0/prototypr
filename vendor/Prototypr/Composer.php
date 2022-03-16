<?php

namespace Prototypr;

class Composer {

	protected $packages = [];
	protected $isProduction = true;

	protected $baseDir = '.';
	protected $localPharPath = '%composer_dir%/composer.phar';
	protected $remotePharPath = 'https://getcomposer.org/composer.phar';
	protected $composerClass = 'Composer\Console\Application';

	protected $env = [
		'COMPOSER_VENDOR_DIR' => '%base_dir%/vendor',
		'COMPOSER_HOME' => '%vendor_dir%/composer',
		'COMPOSER_BIN_DIR' => '%composer_dir%/bin',
		'COMPOSER_CACHE_DIR' => '%composer_dir%/cache',
		'COMPOSER' => '%base_dir%/composer.json',
		'COMPOSER_PROCESS_TIMEOUT' => null,
		'COMPOSER_DISCARD_CHANGES' => null,
		'COMPOSER_NO_INTERACTION' => null,
	];

	protected $productionArgs = [
		'install' => [ '--no-dev', '--prefer-dist', '--optimize-autoloader' ],
		'update' => [ '--no-dev', '--prefer-dist', '--optimize-autoloader' ],
		'dump-autoload' => [ '--no-dev', '--optimize' ],
		'create-project' => [ '--prefer-dist' ],
	];

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
		//disable detect unicode
		ini_set('detect_unicode', 0);
		//standardise env vars
		foreach($this->env as $k => $v) {
			//replace keys
			$this->env[$k] = str_replace([ '%base_dir%', '%vendor_dir%', '%composer_dir%', '\\' ], [ $this->baseDir, $this->env['COMPOSER_VENDOR_DIR'], $this->env['COMPOSER_HOME'], '/' ], $v);
			//set value?
			if($this->env[$k] !== '') {
				putenv($k . "=" . $this->env[$k]);
			}
		}
		//standardise local path
		$this->localPharPath = str_replace([ '%base_dir%', '%vendor_dir%', '%composer_dir%', '\\' ], [ $this->baseDir, $this->env['COMPOSER_VENDOR_DIR'], $this->env['COMPOSER_HOME'], '/' ], $this->localPharPath);
	}

	public function sync(array $opts=[]) {
		//check packages?
		if($this->packages) {
			//get packages
			$pck = $this->getPackages();
			//loop through packages
			foreach($this->packages as $name) {
				if(!isset($pck[$name])) {
					$this->requirePackage($name);
				}
			}
		}
		//set opts
		$opts = array_merge([
			'dir' => '',
			'clear' => false,
			'force' => false,
		], $opts);
		//get composer file
		$composerFile = $this->env['COMPOSER'];
		$composerFileTime = is_file($composerFile) ? filemtime($composerFile) : 0;
		//composer exists?
		if(!$composerFileTime) {
			return null;
		}
		//get autoload file
		$autoloadFile = $this->env['COMPOSER_VENDOR_DIR'] . '/autoload.php';
		//autoloader exists?
		if(is_file($autoloadFile)) {
			require_once($autoloadFile);
		}
		//get lock file
		$lockFile = str_replace('.json', '.lock', $composerFile);
		$lockFileTime = is_file($lockFile) ? filemtime($lockFile) : 0;
		//has latest lock file?
		if(!$opts['force'] && $lockFileTime >= $composerFileTime) {
			return null;
		}
		//run update
		$res = $this->updateDeps();
		//move to dir?
		if($opts['dir']) {
			$this->moveDeps($opts['dir']);
		}
		//clear cache?
		if($opts['clear']) {
			$this->clearCache();
		}
		//touch lock
		touch($lockFile);
		//return
		return $res;
	}

	public function installDeps(array $args=[]) {
		return $this->cmd('install', $args);
	}

	public function updateDeps(array $args=[]) {
		return $this->cmd('update', $args);
	}

	public function moveDeps($destDir) {
		//loop through packages
		foreach($this->getPackages() as $name => $dir) {
			//paths found?
			if(!$paths = glob($dir . '/src/*', GLOB_ONLYDIR)) {
				continue;
			}
			//loop through paths
			foreach($paths as $path) {
				$pathName = str_replace(dirname($path) . '/', '', $path);
				rename($path, $destDir . '/' . $pathName);
			}
			//up one?
			if(strpos($name, '/') !== false) {
				$dir = dirname($dir);
			}
			//delete dir
			$this->rmDir($dir);
		}
	}

	public function createProject($package=null, $path=null, $version=null) {
		//set path?
		if($package && !$path) {
			$path = explode('/', $package);
			$path = $path[0];
		}
		//execute
		return $this->cmd('create-project', [ $package, $path, $version ]);
	}

	public function archiveProject($format=null, $toDir=null) {
		//set args
		$format = '--format=' . ($format ? $format : 'zip');
		$toDir = '--dir=' . ($toDir ? $toDir : '.');
		//execute
		return $this->cmd('archive', [ $format, $toDir ]);
	}

	public function getPackages() {
		//set vars
		$packages = [];
		$lockFile = str_replace('.json', '.lock', $this->env['COMPOSER']);
		//get json
		$json = is_file($lockFile) ? file_get_contents($lockFile) : '';
		$json = $json ? json_decode($json, true) : '';
		//has data?
		if($json) {
			//add package names
			foreach($json['packages'] as $p) {
				$packages[$p['name']] = $this->env['COMPOSER_VENDOR_DIR'] . '/' . $p['name'];
			}
		}
		//return
		return $packages;
	}

	public function requirePackage($package, array $args=[]) {
		array_unshift($args, $package);
		return $this->cmd('require', $args);
	}

	public function findPackage($package, $nameOnly=false) {
		//set args
		$args = [ $package ];
		//name only search?
		if($nameOnly) {
			$args[] = '--only-name';
		}
		//execute
		return $this->cmd('search', $args);
	}

	public function showPackage($package=null, $version=null, $installedOnly=false) {
		//set args
		$args = [ $package, $version ];
		//installed only?
		if($installedOnly) {
			$args[] = '--installed';
		}
		//execute
		return $this->cmd('show', $args);
	}

	public function validateJson() {
		return $this->cmd('validate');
	}

	public function buildAutoloader($optimize=false) {
		//set args
		$args = $optimize ? [ '--optimize' ] : [];
		//execute
		return $this->cmd('dump-autoload', $args);
	}

	public function findLocalChanges($verbose=true) {
		//set args
		$args = $verbose ? [ '--verbose' ] : [];
		//execute
		return $this->cmd('status', $args);
	}

	public function updateComposer($rollback=false) {
		//set args
		$args = $rollback ? [ '--rollback' ] : [];
		//execute
		return $this->cmd('self-update', $args);
	}

	public function clearCache() {
		return $this->rmDir($this->env['COMPOSER_CACHE_DIR']);
	}

	public function cmd($cmd, array $args=[]) {
		//set vars
		$cmd = str_replace('_', '-', trim($cmd));
		$args = $this->formatArgs($args, $cmd);
		$tmpArgv = isset($GLOBALS['argv']) ? $GLOBALS['argv'] : [];
		//store current limits
		$ia = ignore_user_abort();
		$ml = ini_get('memory_limit');
		$tl = ini_get('max_execution_time');
		//update limits
		ignore_user_abort(true);
		ini_set('memory_limit', '512M');
		ini_set('max_execution_time', 0);
		//create dir?
		if(!is_dir($this->env['COMPOSER_HOME'])) {
			mkdir($this->env['COMPOSER_HOME'], 0755, true);
		}
		//init composer?
		if(!class_exists($this->composerClass)) {
			$this->initDownload();
			$this->initBootstrap();
		}
		//update argv
		$GLOBALS['argv'] = $_SERVER['argv'] = explode(' ', $this->localPharPath . ' ' . $cmd . ($args ? ' ' . implode(' ', $args) : ''));
		$GLOBALS['argc'] = $_SERVER['argc'] = count($_SERVER['argv']);
		//start composer app
		$app = new $this->composerClass;
		//no exit allowed
		$app->setAutoExit(false);
		//run app
		$code = $app->run();
		//reset argv
		$GLOBALS['argv'] = $_SERVER['argv'] = $tmpArgv;
		$GLOBALS['argc'] = $_SERVER['argc'] = count($tmpArgv);
		//reset limits
		ignore_user_abort($ia);
		ini_set('memory_limit', $ml);
		ini_set('max_execution_time', $tl);
		//return
		return [
			'cmd' => $cmd,
			'args' => $args,
			'code' => (int) $code,
			'success' => empty($code),
		];
	}

	protected function initDownload() {
		//already downloaded?
		if(is_file($this->localPharPath)) {
			return;
		}
		//can download?
		if($data = file_get_contents($this->remotePharPath)) {
			//get dir
			$dir = dirname($this->localPharPath);
			//create dir?
			if($dir && !is_dir($dir)) {
				mkdir($dir, 0755, true);
			}
			//create file
			file_put_contents($this->localPharPath, $data, LOCK_EX);
		}
	}

	protected function initBootstrap() {
		//load bootstrap file?
		if(!class_exists($this->composerClass)) {
			require_once('phar://' . $this->localPharPath . '/src/bootstrap.php');
		}
	}

	protected function formatArgs(array $args, $cmd=null) {
		//add production args?
		if($cmd && $this->isProduction && isset($this->productionArgs[$cmd])) {
			//loop through array
			foreach($this->productionArgs[$cmd] as $a) {
				$args[] = $a;
			}
		}
		//clean args
		return array_map(function($str) {
			return trim(preg_replace('/\s+/', ' ', $str));
		}, $args);
	}

	protected function rmDir($dir) {
		//dir exists?
		if(!is_dir($dir)) {
			return true;
		}
		//create iterator
		$iterator = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
		//loop through array
		foreach(new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
			if($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		//return
		return (bool) rmdir($dir);
	}

	protected function fnEnabled($fn) {
		//set vars
		$disabled = [];
		//loop through ini get keys
		foreach([ 'disable_functions', 'suhosin.executor.func.blacklist' ] as $k) {
			//loop through functions
			foreach(explode(',', ini_get($k)) as $f) {
				if($f = trim($f)) {
					$disabled[] = $f;
				}
			}
		}
		//return
		return function_exists($fn) && !in_array($fn, $disabled);
	}

}