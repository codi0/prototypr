<?php

namespace Prototypr;

class Platform {

	private $app;

	private $data = [
		'context' => 'standalone',
		'loaded' => 'standalone',
	];

	public function __construct($app) {
		$this->app = $app;
	}

	public function get($key) {
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}

	public function set($key, $val) {
		$this->data[$key] = $val;
	}

	public function check() {
		//loop through methods
		foreach(get_class_methods($this) as $method) {
			//is check method?
			if($method !== 'check' && strpos($method, 'check') === 0) {
				//check passed?
				if($this->$method()) {
					break;
				}
			}
		}
	}

	protected function checkWordpress() {
		//is WP context?
		if(strpos(__FILE__, '/wp-content/') === false) {
			return false;
		}
		//set context
		$this->set('context', 'wordpress');
		//is loaded?
		if(isset($GLOBALS['wpdb']) && $GLOBALS['wpdb']) {
			//set loaded
			$this->set('loaded', 'wordpress');
			//update DB service
			$this->app->service('db', $GLOBALS['wpdb']);
		} else {
			//set vars
			$wpcPath = explode('/wp-content/', __FILE__)[0] . '/wp-config.php';
			//file exists?
			if(!$this->app->config('dbUser') && is_file($wpcPath)) {
				//loop through lines
				foreach(file($wpcPath) as $line) {
					//match found?
					if(preg_match('/DB_HOST|DB_USER|DB_PASS|DB_NAME/', $line, $m)) {
						//format key
						$key = strtolower($m[0]);
						$key = lcfirst(str_replace('_', '', ucwords($key, '_')));
						//format value
						$val = trim(explode(',', $line)[1]);
						$val = trim(explode(')', $val)[0]);
						$val = trim(explode('.', $val)[0]);
						$val = trim(trim($val, '"'), "'");
						//update config?
						if($key && $val) {
							$this->app->config($key, $val);
						}
					}
				}
			}
		}
		//return
		return true;
	}

}