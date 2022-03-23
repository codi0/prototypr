<?php

namespace Prototypr;

class Proxy {

	use ExtendTrait;

	protected $__target;

	public function __construct($target) {
		$this->__target = $target;
	}

	public function __isset($key) {
		return isset($this->__target()->$key);
	}

	public function __unset($key) {
		//get target
		$target = $this->__target();
		//unset property?
		if(isset($target->$key)) {
			unset($target->$key);
		}
	}

	public function __get($key) {
		//get target
		$target = $this->__target();
		//property exists?
		if(property_exists($target, $key)) {
			return $target->$key;
		}
		//not found
		throw new \Exception("Property $key not found");
	}

	public function __set($key, $val) {
		$this->__target()->$key = $val;
	}

	public function __target() {
		//is closure?
		if($this->__target instanceof \Closure) {
			$closure = $this->__target;
			$this->__target = $closure();
		}
		//return
		return $this->__target;
	}

}