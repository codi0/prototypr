<?php

namespace Proto2\Async;

class Loop {

	protected $readStreams = [];
	protected $writeStreams = [];
	
	protected $readListeners = [];
	protected $writeListeners = [];

	protected $ticks;
	protected $timers;
	protected $running = false;

	protected static $master;

	public static function get() {
		//create master?
		if(!self::$master) {
			self::$master = new self;
		}
		//return
		return self::$master;
	}

	public function __construct() {
		//create objects
		$this->ticks = new Ticks();
		$this->timers = new Timers();
	}

	public function isRunning() {
		return $this->running;
	}

	public function addReadStream($stream, $listener) {
		//get stream key
		$key = intval($stream);
		//add to array?
		if(!isset($this->readStreams[$key])) {
			$this->readStreams[$key] = $stream;
			$this->readListeners[$key] = $listener;
		}
	}

	public function removeReadStream($stream) {
		//get stream key
		$key = intval($stream);
		//remove from array?
		if(isset($this->readStreams[$key])) {
			unset($this->readStreams[$key], $this->readListeners[$key]);
		}
	}

	public function addWriteStream($stream, $listener) {
		//get stream key
		$key = intval($stream);
		//add to array?
		if(!isset($this->writeStreams[$key])) {
			$this->writeStreams[$key] = $stream;
			$this->writeListeners[$key] = $listener;
		}
	}

	public function removeWriteStream($stream) {
		//get stream key
		$key = intval($stream);
		//remove from array?
		if(isset($this->writeStreams[$key])) {
			unset($this->writeStreams[$key], $this->writeListeners[$key]);
		}
	}

    public function addTimeout($callback, $interval) {
		return $this->timers->add($callback, $interval, false);
	}

	public function addInterval($callback, $interval) {
		return $this->timers->add($callback, $interval, true);
	}

	public function cancelTimeout($callback) {
		return $this->timers->cancel($callback);
	}

	public function cancelInterval($callback) {
		return $this->timers->cancel($callback);
	}

	public function nextTick($listener) {
		return $this->ticks->add($listener);
	}

	public function run() {
		//already running?
		if($this->running) {
			return;
		}
		//update flag
		$this->running = true;
		//start loop
		while($this->running) {
			//next tick
			$this->ticks->tick();
			$this->timers->tick();
			//get timeout
			if(!$this->running || !$this->ticks->isEmpty()) {
				$timeout = 0;
			} else if($next = $this->timers->next()) {
				$timeout = $next - $this->timers->time();
				if($timeout > 0) {
					$timeout = $timeout * 1000000;
					$timeout = ($timeout > PHP_INT_MAX) ? PHP_INT_MAX : intval($timeout);
				} else {
					$timeout = 0;
				}
			} else if($this->readStreams || $this->writeStreams) {
				$timeout = null;
			} else {
				break;
			}
			//wait
			$this->wait($timeout);
		}
	}

	public function stop() {
		//update flag
		$this->running = false;
	}

	protected function wait($timeout) {
		//is stream available?
		if($this->select($timeout) === false) {
			return;
		}
		//loop through read sterams
		foreach($this->readStreams as $key => $stream) {
			//has listener?
			if(isset($this->readListeners[$key])) {
				call_user_func($this->readListeners[$key], $stream);
			}
		}
		//loop through write streams
		foreach($this->writeStreams as $key => $stream) {
			//has listener?
			if(isset($this->writeListeners[$key])) {
				call_user_func($this->writeListeners[$key], $stream);
			}
		}
	}

	protected function select($timeout) {
		//set vars
		$res = 0;
		$except = null;
		$read =& $this->readStreams;
		$write =& $this->writeStreams;
		//anything to process?
		if($read || $write) {
			//is windows server?
			//Bug workaround: https://docs.microsoft.com/de-de/windows/win32/api/winsock2/nf-winsock2-select
			if(DIRECTORY_SEPARATOR === '\\') {
				//loop through write streams
				foreach($write as $key => $socket) {
					//add to except?
					if(!isset($read[$key]) && @ftell($socket) === 0) {
						$except = $except ?: [];
						$except[$key] = $socket;
					}
				}
			}
			//select stream
			$res = stream_select($read, $write, $except, $timeout === null ? null : 0, $timeout);
			//merge except back?
			if($except) {
				$write = array_merge($write, $except);
			}
        } else if($timeout > 0) {
			usleep($timeout);
		} else if($timeout === null) {
			sleep(PHP_INT_MAX);
		}
		//return
		return $res;
	}

}