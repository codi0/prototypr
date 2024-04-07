<?php

namespace Proto2\Event;

//PSR-14 compatible
class Event {

	protected $name = '';
	protected $params = [];
	protected $stopped = false;

	public function __construct($name, array $params=[]) {
		$this->name = $name;
		$this->params = $params;
    }

	public function __get($key) {
		return isset($this->params[$key]) ? $this->params[$key] : null;
	}

	public function __set($key, $val) {
		$this->params[$key] = $val;
	}

	public function getName() {
		return $this->name;
	}

	public function getParams() {
		return $this->params;
	}

	public function setParams(array $params) {
		$this->params = $params;
	}

	public function stopPropagation() {
		$this->stopped = true;
	}

	public function isPropagationStopped() {
		return $this->stopped;
	}

}