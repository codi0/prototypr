<?php

//PSR-16 compatible (without interfaces)

namespace Proto2\Cache;

abstract class AbstractCache {

	protected $autoGc = 0;

	public function __construct(array $opts=array()) {
		//set properties
		foreach($opts as $k => $v) {
			if(property_exists($this, $k)) {
				$this->$k = $v;
			}
		}
		//auto garbage collection?
		if($this->autoGc > 0 && mt_rand(1, $this->autoGc) == 1) {
			$this->gc();
		}
	}

	abstract public function has($key);

	abstract public function get($key, $default=null);

	public function getMultiple($keys, $default=null) {
		$res = array();
		foreach($keys as $key) {
			$res[$key] = $this->get($key, $default);
		}
		return $res;
	}

	abstract public function set($key, $value, $ttl=null);

	public function setMultiple($values, $ttl=null) {
		foreach($values as $key => $value) {
			$this->set($key, $value, $ttl);
		}
		return true;
	}

	abstract public function delete($key);

	public function deleteMultiple($keys) {
		foreach($keys as $key) {
			$this->delete($key);
		}
		return true;	
	}

	abstract public function clear();

	abstract public function gc();

	public function memoize($id, $fn=null, $ttl=null) {
		//set vars
		$results = [];
		//ID is closure?
		if($id instanceof \Closure) {
			$ttl = $fn;
			$fn = $id;
			$id = '';
		}
		//create function wrapper
		return function() use($id, $fn, $ttl, &$results) {	
			//get args
			$args = func_get_args();
			//create cache ID
			$id = 'memoize/' . md5($id . serialize($args));
			//in local cache?
			if(!isset($results[$id])) {
				//check persistent cache?
				if($ttl !== null) {
					$results[$id] = $this->get($id);
				}
				//call function?
				if(!isset($results[$id])) {
					//execute function
					$results[$id] = call_user_func_array($fn, $args);
					//save to persistent cache?
					if($results[$id] !== null && $ttl !== null) {
						$this->set($id, $results[$id], $ttl);
					}
				}
			}
			//return
			return $results[$id];
		};
	}

}