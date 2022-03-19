<?php

namespace Prototypr;

trait ExtendTrait {

	private $__calls = [];

	public function __call($method, array $args) {
		//target method exists?
		if(isset($this->__target) && method_exists($this->__target, $method)) {
			return $this->__target->$method(...$args);
		}
		//extension found?
		if(isset($this->__calls[$method])) {
			return $this->__calls[$method](...$args);
		}
		//not found
		throw new \Exception("Method $method not found");
	}

	public final function extend($method, $callable = null) {
		//set vars
		$ext = [];
		$target = isset($this->__target) ? $this->__target : $this;
		//sync class?
		if(strpos($method, '\\') > 0) {
			//class data
			$class = $method;
			$opts = is_array($callable) ? $callable : [];
			//default opts
			$opts = array_merge([
				'magic' => false,
				'private' => false,
				'inherited' => false,
				'existing' => false,
				'whitelist' => [],
			], $opts);
			//reflection class
			$ref = new \ReflectionClass($class);
			//loop through methods
			foreach($ref->getMethods() as $rm) {
				//get name
				$method = $rm->getName();
				//skip non-whitelist method?
				if($opts['whitelist'] && !in_array($method, $opts['whitelist'])) {
					continue;
				}
				//skip magic method?
				if(!$opts['magic'] && strpos($method, '__') === 0) {
					continue;
				}
				//skip private method?
				if(!$opts['private'] && !$rm->isPublic()) {
					continue;
				}
				//skip inherited method?
				if(!$opts['inherited'] && $class !== $rm->getDeclaringClass()->name) {
					continue;
				}
				//skip existing method?
				if(!$opts['existing'] && method_exists($target, $method)) {
					continue;
				}
				//add to array
				$ext[$method] = [ $class, $method ];
			}
		} else {
			$ext[$method] = $callable;
		}
		//loop through extensions
		foreach($ext as $method => $callable) {
			//can add callable?
			if($callable = self::createClosure($callable, $target)) {
				$this->__calls[$method] = $callable;
			}
		}
	}

	public final static function createClosure($callable, $thisArg=null) {
		//file cache
		static $fileCache = [];
		//is closure?
		if(!($callable instanceof \Closure)) {
			//static method?
			if(is_string($callable) && strpos($callable, '::') !== false) {
				$callable = explode('::', $callable);
			}
			//is class?
			if(is_array($callable)) {
				//is object?
				if(is_object($callable[0])) {
					$callable[0] = get_class($callable[0]);
				}
				//reflection class
				$ref = new \ReflectionClass($callable[0]);
				$ref = $ref->getMethod($callable[1]);
			} else {
				//reflection function
				$ref = new \ReflectionFunction($callable);
			}
			//get meta data
			$path = $ref->getFileName();
			$startLine = $ref->getStartLine();
			$endLine = $ref->getEndLine();
			//stop here?
			if(empty($path)) {
				return null;
			}
			//get file contents?
			if(!isset($fileCache[$path])) {
				$fileCache[$path] = file($path);
			}
			//extract relevant lines
			$lines = array_slice($fileCache[$path], ($startLine - 1), ($endLine - ($startLine - 1)));
			//format start & end lines
			$lines[0] = 'function(' . explode('(', $lines[0], 2)[1];
			$lines[count($lines)-1] = explode('}', $lines[count($lines)-1], 2)[0] . '}';
			//eval closure
			eval('$callable = ' . implode(PHP_EOL, $lines) . ';');
		}
		//bind $this?
		if($thisArg) {
			$callable = \Closure::bind($callable, $thisArg, $thisArg);
		}
		//return
		return $callable;
	}

}