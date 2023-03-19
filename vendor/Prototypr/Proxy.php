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

	public function __isset($property) {
		//input vars
		$input = [
			'target' => $this->__target(),
			'action' => 'isset',
			'property' => $property,
		];
		//call proxy event
		$output = $this->__event($input);
		//reset vars
		foreach($output as $k => $v) {
			$$k = $v;
		}
		//return
		return isset($target->$property);
	}

	public function __unset($property) {
		//input vars
		$input = [
			'target' => $this->__target(),
			'action' => 'unset',
			'property' => $property,
		];
		//call proxy event
		$output = $this->__event($input);
		//reset vars
		foreach($output as $k => $v) {
			$$k = $v;
		}
		//unset property?
		if(isset($target->$property)) {
			unset($target->$property);
		}
	}

	public function __get($property) {
		//input vars
		$input = [
			'target' => $this->__target(),
			'action' => 'get',
			'property' => $property,
		];
		//call proxy event
		$output = $this->__event($input);
		//reset vars
		foreach($output as $k => $v) {
			$$k = $v;
		}
		//property exists?
		if(property_exists($target, $property)) {
			return $target->$property;
		}
		//not found
		throw new \Exception("Property $property not found");
	}

	public function __set($property, $value) {
		//input vars
		$input = [
			'target' => $this->__target(),
			'action' => 'set',
			'property' => $property,
			'value' => $value,
		];
		//call proxy event
		$output = $this->__event($input);
		//reset vars
		foreach($output as $k => $v) {
			$$k = $v;
		}
		//set property
		$target->$property = $value;
	}

	public function __call($method, array $args) {
		//input vars
		$input = [
			'target' => $this->__target(),
			'action' => 'call',
			'method' => $method,
			'args' => $args,
			'callback' => isset($this->__calls[$method]) ? $this->__calls[$method] : null,
		];
		//call proxy event
		$output = $this->__event($input);
		//reset vars
		foreach($output as $k => $v) {
			$$k = $v;
		}
		//use callback as target?
		if($callback && (!$target || $target === $input['target'])) {
			$target = $callback;
		}
		//has target?
		if($target) {
			//is closure?
			if($target instanceof \Closure) {
				return $target(...$args);
			} else {
				return $target->$method(...$args);
			}
		}
		//not found
		throw new \Exception("Method $method not found");
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

	private function __event(array $args) {
		//check required keys
		foreach([ 'target', 'action' ] as $k) {
			if(!isset($args[$k]) || !$args[$k]) {
				throw new \Exception("Proxy event must include $k");
			}
		}
		//call event?
		if($this->__kernel) {
			//execute event
			$res = $this->__kernel->event('proxy.call', $args);
			//update vars?
			if($res && is_array($res)) {
				//loop through vars
				foreach($res as $k => $v) {
					if(isset($args[$k])) {
						$args[$k] = $v;
					}
				}
			}
		}
		//return
		return $args;
	}

}