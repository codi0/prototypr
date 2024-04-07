<?php

namespace Proto2\Http;

class Input {

	protected $validator;

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
		//JIT check
		isset($_ENV) && isset($_SERVER) && isset($_REQUEST);
	}

	public function __call($name, array $args) {
		//set vars
		$opts = [];
		$param = isset($args[0]) ? $args[0] : null;
		//format opts?
		if(count($args) > 1) {
			if(!is_array($args[1]) || isset($args[1][0])) {
				$opts = array( 'default' => $args[1] );
			} else {
				$opts = $args[1];
			}
		}
		//check globals
		return $this->find($name, $param, $opts);
	}

	public function has($global, $param=null) {
		//format global
		$global = '_' . trim(strtoupper($global), '_');
		//global only?
		if($param === null) {
			return isset($GLOBALS[$global]);
		}
		//check param
		return isset($GLOBALS[$global]) && isset($GLOBALS[$global][$param]);
	}

	public function find($global, $param=null, array $opts=[]) {
		//set defaults
		$opts = array_merge([
			'field' => null,
			'label' => null,
			'default' => null,
			'validate' => null,
			'filter' => 'xss',
		], $opts);
		//set vars
		$global = '_' . trim(strtoupper($global), '_');
		$param = str_replace([ '*', '..*' ], '.*', $param);
		$default = ($opts['default'] === null) ? ($param ? '' : []) : $opts['default'];
		$validOpts = [ 'field' => $opts['field'] ?: $param, 'label' => $opts['label'] ];
		//find global
		if($global === '_' || $global === '_REQUEST') {
			//$_POST takes priority
			$data = array_merge($_GET, $_POST);
		} else {
			//global exists?
			if(isset($GLOBALS[$global]) && is_array($GLOBALS[$global])) {
				$data = $GLOBALS[$global];
			} else {
				return $default;
			}
		}
		//filter data
		if($param && strpos($param, '.*') === false) {
			//single param
			$data = isset($data[$param]) ? $data[$param] : $default;
		} else if($param) {
			//wildcard param
			foreach($data as $k => $v) {
				if(!preg_match('/' . $param . '/', $k)) {
					unset($data[$k]);
				}
			}
		}
		//filter?
		if($opts['filter']) {
			$data = $this->validator->filter($opts['filter'], $data, $validOpts);
		}
		//validate?
		if($opts['validate']) {
			$this->validator->validate($opts['validate'], $data, $validOpts);
		}
		//return
		return $data;
	}

	public function files() {
		return UploadedFile::createFromGlobals();
	}

}