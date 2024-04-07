<?php

namespace Proto2\App;

class Platform {

	protected $registry;
	protected $checks = [];

	protected $vars = [
		'context' => 'standalone',
		'loaded' => 'standalone',
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

	public function getVar($key) {
		return isset($this->vars[$key]) ? $this->vars[$key] : null;
	}

	public function setVar($key, $val) {
		//add to property
		$this->vars[$key] = $val;
		//return
		return true;
	}

	public function addCheck($platform, $callback) {
		//add to property
		$this->checks[strtolower($platform)] = $callback;
		//return
		return true;
	}

	public function run() {
		//set vars
		$checks = [];
		//loop through class methods
		foreach(get_class_methods($this) as $method) {
			//is check method?
			if($method !== 'check' && strpos($method, 'check') === 0) {
				$platform = substr($method, 5);
				$checks[strtolower($platform)] = [ $this, $method ];
			}
		}
		//add custom checks
		foreach($this->checks as $platform => $callback) {
			$checks[$platform] = $callback;
		}
		//run checks
		foreach($checks as $platform => $callback) {
			//check passed?
			if(call_user_func($callback)) {
				break;
			}
		}
	}

	protected function checkWordpress() {
		//is WP context?
		if(strpos(__FILE__, '/wp-content/') === false) {
			return false;
		}
		//set context
		$this->setVar('context', 'wordpress');
		//get objects
		$db = $this->registry->get('db');
		$config = $this->registry->get('config');
		//is loaded?
		if(isset($GLOBALS['wpdb']) && $GLOBALS['wpdb']) {
			//set loaded
			$this->setVar('loaded', 'wordpress');
			//get plugin file
			$file = $config->get('paths.base') . '/index.php';
			//update config
			$config->set('urls.base', plugin_dir_url($file));
			//add db conn
			$db->addConn($GLOBALS['wpdb']);
		} else {
			//set vars
			$dbOpts = [ 'host' => 'localhost' ];
			$wpcPath = explode('/wp-content/', __FILE__)[0] . '/wp-config.php';
			//file exists?
			if(!isset($dbOpts['user']) && !$dbOpts['user'] && is_file($wpcPath)) {
				//loop through lines
				foreach(file($wpcPath) as $line) {
					//match found?
					if(preg_match('/DB_HOST|DB_USER|DB_PASS|DB_NAME/', $line, $m)) {
						//format key
						$key = strtolower($m[0]);
						$key = str_replace('db_', '', $key);
						//format value
						$val = trim(explode(',', $line)[1]);
						$val = trim(explode(')', $val)[0]);
						$val = trim(explode('.', $val)[0]);
						$val = trim(trim($val, '"'), "'");
						//update config?
						if($key && $val) {
							$dbOpts[$key] = $val;
						}
					}
				}
				//add db conn
				$db->addConn($dbOpts);
			}
		}
		//return
		return true;
	}

}