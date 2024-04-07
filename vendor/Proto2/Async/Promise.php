<?php

namespace Proto2\Async;

class Promise {

	protected $value;
	protected $canceller;
	protected $status = 'pending';

	protected $resolveCallbacks = [];
	protected $rejectCallbacks = [];

	public function __construct(\Closure $resolver) {
		//call resolver
		$resolver->call($this);
	}

	public function getStatus() {
		return $this->status;
	}

	public function resolve($value = null) {
		//is pending?
		if($this->status === 'pending') {
			//update status
			$this->status = 'fulfilled';
			//run callbacks
			$this->runCallbacks($this->resolveCallbacks, $value);
		}
	}

	public function reject($value = null) {
		//is pending?
		if($this->status === 'pending') {
			//update status
			$this->status = 'rejected';
			//run callbacks
			$this->runCallbacks($this->rejectCallbacks, $value);
		}
	}

	public function then(\Closure $onFulfilled = null, \Closure $onRejected = null) {
		//check status
		if($this->status === 'fulfilled') {
			$this->runCallbacks([ $onFulfilled ]);
		} else if($this->status === 'rejected') {
			$this->runCallbacks([ $onRejected ]);
		} else if($onFulfilled) {
			$this->resolveCallbacks[] = $onFulfilled;
		} else if($onRejected) {
			$this->rejectCallbacks[] = $onRejected;
		}
		//chain it
		return $this;
	}

	public function otherwise(\Closure $onRejected) {
		return $this->then(null, $onRejected);
	}

	public function always(\Closure $onFulfilledOrRejected) {
		return $this->then($onFulfilledOrRejected, $onFulfilledOrRejected);
	}

	protected function runCallbacks(array $callbacks, $value) {
		//loop through callbacks
		foreach($callbacks as $callback) {
			//run callback
			$value = $callback($this->value ?? $value);
			//cache value?
			if($value !== null) {
				$this->value = $value;
			}
		}
	}

}