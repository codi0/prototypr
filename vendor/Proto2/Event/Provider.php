<?php

namespace Proto2\Event;

//PSR-14 compatible
class Provider {

	protected $listeners = [];

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
	}

	public function getListenersForEvent($event) {
		//extract name?
		if(is_object($event)) {
			$event = $event->getName();
		}
		//return
		return isset($this->listeners[$event]) ? $this->listeners[$event] : [];
	}

	public function listen($name, $listener) {
		//create array?
		if(!isset($this->listeners[$name])) {
			$this->listeners[$name] = [];
		}
		//store listener?
		if(!in_array($listener, $this->listeners[$name], true)) {
			$this->listeners[$name][] = $listener;
		}
	}

	public function unlisten($name, $listener=null) {
		//create array?
		if(!isset($this->listeners[$name])) {
			return;
		}
		//remove all?
		if($listener === null) {
			unset($this->listeners[$name]);
			return;
		}
		//loop through listeners
		foreach($this->listeners[$name] as $key => $val) {
			//listener matched?
			if($val === $listener) {
				unset($this->listeners[$name][$key]);
				break;
			}
		}
	}

}