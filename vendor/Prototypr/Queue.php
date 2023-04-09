<?php

namespace Prototypr;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class Queue {

	protected $hosts = [];
	protected $fallbackDir = '';

	protected $consumerTtl = 60;
	protected $maxConsumers = 10;
	protected $consumerSpawnUrl = '';

	protected $messageOpts = [
		'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,	
	];

	protected $conn;
	protected $channel;

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
		//set default host?
		if(!$this->hosts) {
			$this->addHost('localhost', 5672, 'guest', 'guest');
		}
		//wrap hosts?
		if($this->hosts && !isset($this->hosts[0])) {
			$this->hosts = [ $this->hosts ];
		}
		//set fallback dir?
		if(!$this->fallbackDir) {
			$this->fallbackDir = sys_get_temp_dir();
		}
	}

	public function __destruct() {
		$this->close();
	}

	public function addHost($host, $port, $user, $password, $vhost=null) {
		$this->hosts[] = [ 'host' => $host, 'port' => $port, 'user' => $user, 'password' => $password, 'vhost' => null ];
	}

	public function connect() {
		//has connection?
		if(!$this->conn) {
			$this->conn = AMQPStreamConnection::create_connection($this->hosts);
		}
		//return
		return $this->conn;
	}

	public function close() {
		//close channel?
		if($this->channel) {
			$this->channel->close();
		}
		if($this->conn) {
			$this->conn->close();
		}
		//return
		return true;
	}

	public function channel() {
		//has channel?
		if(!$this->channel) {
			$this->channel = $this->connect()->channel();
		}
		//return
		return $this->channel;
	}

	public function sendMessage($queue, $message, array $opts=[]) {
		//set vars
		$result = false;
		$tryFallback = !isset($opts['fallback']) || $opts['fallback'];
		//try primary
		try {
			//get channel
			$channel = $this->channel();
			//declare queue
			$meta = $channel->queue_declare($queue, false, false, false, false);
			//auto-spawn consumer?
			if($this->consumerSpawnUrl && $meta[2] < 1) {
				register_shutdown_function([ $this, 'spawnConsumer' ]);
			}
			//wrap message?
			if(!($message instanceof AMQPMessage)) {
				//set message opts
				$messageOpts = isset($opts['message']) ? $opts['message'] : [];
				$messageOpts = array_merge($this->messageOpts, $messageOpts);
				//create message object
				$message = new AMQPMessage($message, $messageOpts);
			}
			//send to queue
			$channel->basic_publish($message, '', $queue);
			//primary sent
			$result = 'primary';
		} catch(\Exception $e) {
			//do nothing
		}
		//try fallback?
		if(!$result && $tryFallback) {
			//fallback sent?
			if($this->sendFallback($queue, $message)) {
				$result = 'fallback';
			}
		}
		//return
		return $result;
	}

	public function consumeMessage(AMQPMessage $message, $callback, $startTime=null, $ttl=null) {
		//set vars
		$channel = $message->getChannel();
		//callable or process?
		if(is_callable($callback)) {
			$result = $callback($message);
		} else {
			$result = $this->spawnProcess($callback, $message);
		}
		//acknowledge success?
		if($result === true) {
			$channel->basic_ack($message->delivery_info['delivery_tag']);
		}
		//stop consumer?
		if($ttl && (($startTime + $ttl) < time())) {
			$channel->basic_cancel($message->delivery_info['consumer_tag']);
		}
	}

	public function createConsumer($queue, $callback, array $opts=[]) {
		//set vars
		$that = $this;
		$startTime = time();
		$ttl = isset($opts['ttl']) ? $opts['ttl'] : $this->consumerTtl;
		//get channel
		$channel = $this->channel();
		//declare queue
		$meta = $channel->queue_declare($queue, false, false, false, false);
		//reached max consumers?
		if($this->maxConsumers && $meta[2] > $this->maxConsumers) {
			exit();
		}
		//create callback wrapper
		$cb = function(AMQPMessage $message) use($that, $startTime, $ttl, $callback) {
			$that->consumeMessage($message, $callback, $startTime, $ttl);
		}; 
		//setup consumer
		$channel->basic_consume($queue, '', false, true, false, false, $cb);
		//remove time limit
		set_time_limit(0);
		ignore_user_abort(true);
		//wait for messages
		while(count($channel->callbacks)) {
			$channel->wait();
		}
	}

	public function spawnConsumer() {
		//has url?
		if(!$this->consumerSpawnUrl) {
			throw new \Exception("Consumer spawn URL not set");
		}
		//parse url
		$parts = parse_url($this->consumerSpawnUrl);
		$port = isset($parts['port']) ? $parts['port'] : ($parts['scheme'] == 'https' ? 443 : 80);
		$scheme = ($port == 443) ? 'ssl' : 'tcp';
		$localhost = in_array($parts['host'], [ 'localhost', '127.0.0.1', '::1' ]);
		//set context
		$context = stream_context_create([
			'ssl' => [
				'allow_self_signed' => $localhost,
			]
		]);
		//make request
		if($fp = stream_socket_client($scheme . '://' . $parts['host'] . ':' . $port, $errno, $errstr, 3, STREAM_CLIENT_ASYNC_CONNECT|STREAM_CLIENT_CONNECT, $context)) {
			stream_set_timeout($fp, 0, 100000);
			$out  = "GET " . $parts['path'] . " HTTP/1.0\r\n";
			$out .= "Host: " . $parts['host'] . "\r\n";
			$out .= "Connection: Close\r\n\r\n";
			$res = fwrite($fp, $out);
			fread($fp, 1);
			fclose($fp);
			return ($res > 0);
		}
		//failed
		return false;
	}

	public function processFallback($ttl=null) {
		//set start time
		$found = 0;
		$success = 0;
		$startTime = time();
		//find files to process
		foreach(glob($this->fallbackDir . '/rmq-*.log') as $file) {
			//stop here?
			if($ttl && (($startTime + $ttl) < time())) {
				break;
			}
			//set vars
			$found++;
			$delete = false;
			$data = file_get_contents($file);
			//process data?
			if(is_string($data)) {
				//decode message
				$data = json_decode($data, true);
				//re-send message
				$delete = $this->sendMessage($data['queue'], $data['message'], [
					'fallback' => false,
				]);
			} else {
				//invalid message
				$delete = true;
			}
			//delete file?
			if($delete) {
				$success++;
				unlink($file);
			}
		}
		//return
		return [ $found, $success ];
	}

	protected function sendFallback($queue, $message) {
		//extract message body?
		if($message instanceof AMQPMessage) {
			$message = $message->body;
		}
		//build file path
		$path = $this->fallbackDir . '/rmq-' . time() . '-' . mt_rand(100000, 999999) . '.log';
		//format data
		$data = [ 'queue' => $queue, 'message' => $message ];
		//save data
		return file_put_contents($path, json_encode($data), LOCK_EX) !== false;
	}

	protected function spawnProcess($file, AMQPMessage $message) {
		//set vars
		$pipes = [];
		//create process
		$process = proc_open(PHP_BINARY . ' -d display_errors=stderr ' . $file, [
			0 => [ "pipe", "r" ],
			1 => [ "pipe", "w" ],
			2 => [ "pipe", "w" ],
		], $pipes, sys_get_temp_dir(), null);
		//valid process?
		if(is_resource($process)) {
			//send body
			fwrite($pipes[0], $message->body);
			fclose($pipes[0]);
			//check for errors
			$stdErr = stream_get_contents($pipes[2]);
			fclose($pipes[2]);
			//success?
			if(proc_close($process) === 0 && empty($stdErr)) {
				return true;
			}
		}
		//failed
		return false;
	}

}