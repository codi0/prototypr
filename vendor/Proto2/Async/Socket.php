<?php

namespace Proto2\Async;

class Socket extends Emitter {

	protected $loop;
	protected $stream;
	protected $listening = false;

	public function __construct($uri, $loop = null, array $context = []) {
		//set loop
		$this->loop = $loop ?: Loop::get();
		//set context
		$context = stream_context_create([ 'socket' => $context + [ 'backlog' => 511 ] ]);
		//attempt connection
        $this->stream = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
		//error found?
		if(!$this->stream) {
			throw new \RuntimeException("Failed to listen on $uri: $errstr");
		}
		//non-blocking
		stream_set_blocking($this->stream, false);
		//start listening
		$this->resume();
	}

	public function write($client, $data) {
		//is listening?
		if(!$this->listening) {
			return;
		}
		//add write stream
		$this->loop->addWriteStream($client, function($stream) use($data) {
			//write loop
			while(true) {
				//write to connection
				$written = fwrite($stream, $data);
				//write completed?
				if(!$written || $written >= strlen($data)) {
					$this->loop->removeWriteStream($stream);
					break;
				}
				//trim data
				$data = substr($data, $written);
			}
		});
	}

    public function resume() {
		//is listening?
		if($this->listening) {
			return;
		}
		//add read stream
		$this->loop->addReadStream($this->stream, function($stream) {
			try {
				//accept connection
				$client = stream_socket_accept($stream, 0);
			} catch(\Exception $e) {
				//error event
				$this->emit('error', [ $e, $this->loop, $this ]);
				return;
			}
			//connection event
			$this->emit('connection', [ $client, $this->loop, $this ]);
			//read client input
			$this->loop->addReadStream($client, function($client) {
				//has data?
				if($data = stream_get_contents($client, 65536)) {
					//data event
					$this->emit('data', [ $data, $this->loop, $this, $client ]);
				}
			});
		});
		//update flag
		$this->listening = true;
	}

    public function pause() {
		//is listening?
		if(!$this->listening) {
			return;
		}
		//remove read stream?
		if(is_resource($this->stream)) {
			$this->loop->removeReadStream($this->stream);
		}
		//update flag
		$this->listening = false;
    }

	public function close() {
		//pause
		$this->pause();
		//close stream?
		if(is_resource($this->stream)) {
			fclose($this->stream);
		}
	}

}