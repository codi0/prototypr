<?php

namespace Prototypr;

class Platform {

	use ConstructTrait;

	private $data = [
		'context' => 'standalone',
		'loaded' => 'standalone',
	];

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
			//wrap $wpdb in proxy
			$proxy = new Proxy($GLOBALS['wpdb']);
			$proxy->extend('Prototypr\Db');
			//update DB service
			$this->kernel->service('db', $proxy);
			//update base url
			$file = $this->kernel->config('base_dir') . '/index.php';
			$this->kernel->config('base_url', plugin_dir_url($file));
		} else {
			//set vars
			$wpcPath = explode('/wp-content/', __FILE__)[0] . '/wp-config.php';
			$dbOpts = $this->kernel->config('db_opts') ?: [];
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
				//update config
				$this->kernel->config('db_opts', $dbOpts);
			}
		}
		//return
		return true;
	}

}