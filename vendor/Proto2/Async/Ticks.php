<?php

namespace Proto2\Async;

class Ticks {

	protected $queue;

	public function __construct() {
		//create queue object
        $this->queue = new \SplQueue();
    }

	public function isEmpty() {
		return $this->queue->isEmpty();
	}

	public function add($listener) {
		return $this->queue->enqueue($listener);
	}

	public function tick() {
		//count queue
		$count = $this->queue->count();
		//execute
		while($count--) {
            call_user_func($this->queue->dequeue());
		}
	}

}