<?php

namespace Proto2\Cache;

class Memory extends AbstractCache {

	protected $data = array();

	public function has($key) {
		return array_key_exists($key, $this->data);
	}

	public function get($key, $default=null) {
		return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
	}

	public function set($key, $value, $ttl=null) {
		$this->data[$key] = $value;
		return true;
	}

	public function delete($key) {
		if(array_key_exists($key, $this->data)) {
			unset($this->data[$key]);
		}
		return true;
	}

	public function clear() {
		$this->data = array();
		return true;
	}

	public function gc() {
		return true;
	}

}