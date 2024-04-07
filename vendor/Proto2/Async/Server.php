<?php

namespace Proto2\Async;

class Server extends Emitter {

	protected $loop;
	protected $socket;
	protected $httpKernel;

	protected $type = 'http';
	protected $display = true;

	public function __construct(array $opts=[]) {
		//loop through opts
		foreach($opts as $k => $v) {
			if(property_exists($this, $k)) {
				$this->$k = $v;
			}
		}
		//set type?
		if(!$this->type) {
			$this->type = 'http';
		}
		//set loop?
		if(!$this->loop) {
			$this->loop = Loop::get();
		}
	}

	public function create($host, $port, $callback) {
		//set vars
		$callable = is_callable($this->type) ? $this->type : [ $this, $this->type . 'Run' ];
		//create socket
		try {
			$this->socket = new Socket($host . ':' . $port, $this->loop);
		} catch(\Exception $e) {
			throw $e;
		}
		//show message?
		if($this->display) {
			echo 'Server listening...' . "\n";
		}
		//listen for error
		$this->socket->on('error', function($e) {
			$this->emit('error', func_get_args());
		});
		//listen for connection
		$this->socket->on('connection', function($conn, $loop) {
			//show message?
			if($this->display) {
				echo 'Client connected...' . "\n";
			}
			//connect event
			$this->emit('connection', func_get_args());
		});
		//listen for data
		$this->socket->on('data', function($data, $loop, $socket, $client) use($callable, $callback) {
			//show message?
			if($this->display) {
				echo $data;
			}
			//data event
			$this->emit('data', func_get_args());
			//handle data
			$callable($socket, $client, $data, $callback);
		});
		//run loop
		$this->loop->run();
	}

	public function resume() {
		return $this->socket->resume();
	}

	public function pause() {
		return $this->socket->pause();
	}

	public function close() {
		return $this->socket->close();
	}

	public function middleware($callback) {
		//register middleware?
		if($this->httpKernel) {
			$this->httpKernel->middleware($callback);
		}
		//chain it
		return $this;
	}

	protected function httpRun($socket, $client, $data, $callback = null) {
		//has kernel?
		if(!$this->httpKernel) {
			throw new \Exception("HTTP Kernel object required");
		}
		//has headers?
		if(strpos($data, "\r\n\r\n") === false) {
			return;
		}
		//add middleware
		$this->httpKernel->middleware(function($request, $next) use($client) {
			//get client IP
			$ip = stream_socket_get_name($client, true);
			$ip = str_replace([ '[', ']', ], '', preg_split('/[^\:]\:[0-9]/', $ip)[0]);
			//set client attributes
			$request = $request->withAttribute('client_conn', $client)->withAttribute('client_ip', $ip);
			//return
			return $next($request);
		});
		//run http kernel
		$response = $this->httpKernel->run($data, $callback);
		//write to socket
		$socket->write($client, $response->getRaw());
	}

}