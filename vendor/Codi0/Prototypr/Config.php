<?php

namespace Codi0\Prototypr;

class Config {

	protected $data = [];
	protected $readOnly = false;

	public function __construct(array $data=[], $readOnly=false) {
		//set data
		$this->data = $data;
		//is read only?
		$this->readOnly = (bool) $readOnly;
	}

	public function __isset($key) {
		return isset($this->data[$key]);
	}

	public function __unset($key) {
		if(array_key_exists($key, $this->data)) {
			unset($this->data[$key]);
		}
	}

	public function __get($key) {
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}

	public function __set($key, $val) {
		//can read?
		if($this->readOnly) {
			throw new \Exception("Config data is read only");
		}
		//set data
		$this->data[$key] = $val;
	}

}