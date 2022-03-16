<?php

namespace Prototypr;

class Proxy {

	use ExtendTrait;

	protected $__target;

	public function __construct($target) {
		$this->__target = $target;
	}

	public function __isset($key) {
		return isset($this->__target->$key);
	}

	public function __unset($key) {
		if(isset($this->__target->$key)) {
			unset($this->__target->$key);
		}
	}

	public function __get($key) {
		//property exists?
		if(property_exists($this->__target, $key)) {
			return $this->__target->$key;
		}
		//not found
		throw new \Exception("Property $key not found");
	}

	public function __set($key, $val) {
		$this->__target->$key = $val;
	}

}