<?php

namespace Prototypr;

class Proxy {

	use ExtendTrait;

	protected $__target;
	protected $__kernel;

	public function __construct($target, $kernel=null) {
		$this->__target = $target;
		$this->__kernel = $kernel ?: prototypr();
	}

	public function __isset($key) {
		//get target
		$target = $this->__target();
		//return
		return isset($target->$key);
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
		//get target
		$target = $this->__target();
		//set property
		$target->$key = $val;
	}

	public function __target() {
		//is closure?
		if($this->__target instanceof \Closure) {
			$closure = $this->__target;
			$this->__target = $closure();
		}
		//is object?
		if(!is_object($this->__target)) {
			throw new \Exception("Proxy target must be an object");
		}
		//return
		return $this->__target;
	}

}