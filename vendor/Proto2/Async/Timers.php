<?php

namespace Proto2\Async;

class Timers {

	protected $time;
	protected $timers = [];
	protected $schedule = [];
	protected $sorted = true;

	public function __construct() {
		//nothing to do
	}

	public function time($update = false) {
		//update time?
		if($update || !$this->time) {
			$this->time = function_exists('hrtime') ? hrtime(true) * 1e-9 : microtime(true);
		}
		//return
		return $this->time;
	}

    public function isEmpty() {
		return count($this->timers) === 0;
	}

    public function has($callback) {
		//loop through timers
		foreach($this->timers as $timer) {
			//match found?
			if($timer['callback'] === $callback) {
				return true;
			}
		}
		//not found
		return false;
    }

	public function add($callback, $interval, $periodic = false) {
		//create array
		$timer = [
			'callback' => $callback,
			'interval' => intval($interval),
			'periodic' => $periodic,
		];
		//add to array
		$this->timers[] = $timer;
		//add to scheule
		$this->schedule[] = $timer['interval'] + $this->time(true);
		//mark not sorted
		$this->sorted = false;
	}

	public function remove($callback) {
		//loop through timers
		foreach($this->timers as $key => $timer) {
			//match found?
			if($timer['callback'] === $callback) {
				unset($this->timers[$key], $this->schedule[$key]);
			}
		}
	}

    public function next() {
		//sort now?
		if(!$this->sorted) {
			$this->sorted = true;
			asort($this->schedule);
		}
		//return
		return reset($this->schedule);
	}

	public function tick() {
		//anything scheduled?
		if(!$this->schedule) {
			return;
		}
		//sort now?
		if(!$this->sorted) {
			$this->sorted = true;
			asort($this->schedule);
		}
		//get time
		$time = $this->time(true);
		//loop through schedule
		foreach($this->schedule as $key => $next) {
			//ready to execute?
            if($next >= $time) {
                break;
            }
			//already removed?
			if(!isset($this->schedule[$key]) || $this->schedule[$key] !== $next) {
				continue;
			}
			//get timer
			$timer = $this->timers[$key];
			//execute callback
			call_user_func($timer['callback'], $timer);
			//re-schedule timer?
			if($timer['periodic'] && isset($this->timers[$key])) {
				//udpate time
				$this->schedule[$key] = $timer['interval'] + $time;
				//mark as not sorted
				$this->sorted = false;
			} else {
				//remove timer
				unset($this->timers[$key], $this->schedule[$key]);
			}
		}
	}

}