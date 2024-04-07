<?php

namespace Proto2\Async;

class Emitter {

	protected $listeners = [];

	public function on($event, $callback) {
		//create array?
		if(!isset($this->listeners[$event])) {
			$this->listeners[$event] = [];
		}
		//add listener
		$this->listeners[$event][] = $callback;
	}

	public function off($event, $callback = null) {
		//has callback?
		if(!$callback) {
			//remove all listeners
			$this->removeAllListeners($event);
		} else if(isset($this->listeners[$event])) {
			//loop through listeners
			foreach($this->listeners as $key => $listener) {
				//match found?
				if($listener === $callback) {
					unset($this->listeners[$event][$key]);
				}
			}
		}
	}

	public function emit($event, array $args = []) {
		//event exists?
		if(isset($this->listeners[$event])) {
			//loop through listeners
			foreach($this->listeners[$event] as $listener) {
				$listener(...$args);
			}
		}
	}

	public function removeAllListeners($event = null) {
		//has event?
		if(!$event) {
			$this->listeners = [];
		} else if(isset($this->listeners[$event])) {
			unset($this->listeners[$event]);
		}
	}

}