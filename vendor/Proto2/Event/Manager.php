<?php

namespace Proto2\Event;

//PSR-14 compatible
class Manager {

	protected $provider;

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
		//create provider?
		if(!$this->provider) {
			$this->provider = new Provider;
		}
	}

	public function has($name) {
		return count($this->provider->getListenersForEvent($name)) > 0;
	}

	public function add($name, $callback) {
		$this->provider->listen($name, $callback);
	}

	public function remove($name, $callback) {
		$this->provider->unlisten($name, $callback);
	}

	public function clear($name) {
		$this->provider->unlisten($name);
	}

	public function dispatch($event, array $params=[]) {
		//create event?
		if(!is_object($event)) {
			$event = new Event($event, $params);
		}
		//loop through listeners
		foreach($this->provider->getListenersForEvent($event) as $callback) {
			//is callable?
			if(is_callable($callback)) {
				call_user_func($callback, $event);
			} else {
				//create object?
				if(is_string($callback)) {
					$callback = new $callback;
				}
				//call method
				$callback->process($event);
			}
			//stop here?
			if($event->isPropagationStopped()) {
				break;
			}
		}
		//return
		return $event;
	}

}