<?php

namespace Proto2\Cache;

class Memcache extends AbstractCache {

	protected $memcache = null;
	protected $servers = array();

	public function __construct(array $opts=array()) {
		//call parent
		parent::__construct($opts);
		//module exists?
		if(!extension_loaded('memcached') && !extension_loaded('memcache')) {
			throw new \Exception("Memcache extension not loaded");
		}
		//set memcache?
		if(!$this->memcache) {
			//create object
			if(class_exists('Memcached', false)) {
				$this->memcache = new \Memcached;
			} elseif(class_exists('MemcachePool', false)) {
				$this->memcache = new \MemcachePool;
			} else {
				$this->memcache = new \Memcache;
			}
			//set default server?
			if(empty($this->servers)) {
				$this->servers[] = array( 'host' => '127.0.0.1', 'port' => 11211 );
			}
		}
		//connect to servers
		foreach($this->servers as $server) {
			if(isset($server['host']) && isset($server['port'])) {
				$this->connect($server['host'], $server['port']);
			}
		}
	}

	public function memcache() {
		return $this->memcache;
	}

	public function connect($host, $port) {
		return $this->memcache->addServer($host, $port);
	}

	public function close() {
		if(method_exists($this->memcache, 'quit')) {
			return $this->memcache->quit();
		} else {
			return $this->memcache->close();
		}
	}

	public function has($key) {
		return $this->memcache->get($key) !== false;
	}

	public function get($key, $default=null) {
		$res = $this->memcache->get($key);
		return ($res !== false) ? $res : $default;
	}

	public function set($key, $value, $ttl=null) {
		if($this->memcache instanceof \Memcached) {
			return (bool) $this->memcache->set($key, $value, $ttl);
		} else {
			return (bool) $this->memcache->set($key, $value, 0, $ttl);
		}
	}

	public function delete($key) {
		return (bool) $this->memcache->delete($key);
	}

	public function clear() {
		return (bool) $this->memcache->flush();
	}

	public function gc() {
		return true;
	}

}