<?php

namespace Proto2\Route;

class Route {

	protected $name;
	protected $callback;

	protected $params;
	protected $methods = [];
	protected $attributes = [];

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
		//valid route?
		if($this->name === null || $this->callback === null) {
			throw new \Exception("Route requires a name and callback");
		}
		//parse name
		$parts = explode('!', $this->name);
		$this->name = trim(array_pop($parts), '/');
		//format methods
		$this->methods = array_map('strtoupper', $this->methods);
		//loop through array
		foreach($parts as $p) {
			if(strtoupper($p) === $p) {
				if(!in_array($p, $this->methods)) {
					$this->methods[] = $p;
				}
			} else {
				$this->attributes[$p] = true;
			}
		}
	}

	public function getName() {
		return $this->name;
	}

	public function getMethods() {
		return $this->methods;
	}

	public function getCallback() {
		return $this->callback;
	}

	public function modifyCallback($fn) {
		$this->callback = $fn($this->callback);
		return $this->callback;
	}

	public function getParams() {
		return $this->params ?: [];
	}

	public function getParam($key) {
		return ($this->params && isset($this->params[$key])) ? $this->params[$key] : null;
	}

	public function setParams(array $params, $force=false) {
		if(!$force && $this->params !== null) {
			throw new \Exception("Route params already set");
		}
		$this->params = $params;
		return true;
	}

	public function getAttributes() {
		return $this->attributes ?: [];
	}

	public function getAttribute($key) {
		return isset($this->attributes[$key]) ? $this->attributes[$key] : null;
	}

	public function setAttribute($key, $val) {
		$this->attributes[$key] = $val;
		return true;
	}

}