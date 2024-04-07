<?php

namespace Proto2\Async;

class Process {

	protected $pipes = [];
	protected $processes = [];

	protected $baseUrl = '';
	protected $phpBinary = 'php';

	protected $nonblocking = true;
	protected $reading = false;

	protected $descriptors = [
		0 => [ 'pipe', 'r' ], // stdin
		1 => [ 'pipe', 'w' ], // stdout
		2 => [ 'pipe', 'w' ], //stderr
	];

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

	public function __destruct() {
		//auto-close
		$this->close();
	}

	public function run($filePath, array $args=[], $content='') {
		//add and read
		$result = $this->add($filePath, $args)->write($content)->read();
		//close
		$this->close();
		//return
		return $result;
	}

	public function add($filePath, array $args=[]) {
		//is reading?
		if($this->reading) {
			throw new \Exception("Open processes already running");
		}
		//file exists?
		if(!$filePath || !is_file($filePath)) {
			throw new \Exception("File path does not exist");
		}
		//format args
		foreach($args as $k => $v) {
			//use key?
			if($k && !is_numeric($k)) {
				//cache key
				$key = $k;
				//add dashes?
				if($k[0] !== '-') {
					$k = '--' . $k;
				}
				//add to args
				$args[$key] = $k . '=' . $v;
			}
		}
		//set base url?
		if($this->baseUrl && !isset($args['baseUrl'])) {
			$args['baseUrl'] = '--baseUrl=' . $this->baseUrl;
		}
		//format command
		$cmd = implode(' ', array_map('escapeshellarg', $args));
		$cmd = trim($this->phpBinary . ' -d display_errors=stderr ' . $filePath . ' ' . $cmd);
		//open process?
		if(!$proc = proc_open($cmd, $this->descriptors, $pipes)) {
			throw new \Exception("Unable to start process $filepath");
		}
		//add to array
		$this->processes[] = $proc;
		$this->pipes[] = $pipes;
		//non-blocking output?
		if($this->nonblocking) {
			stream_set_blocking($pipes[1], 0);
		}
		//chain it
		return $this;
	}

	public function read() {
		//set vars
		$result = [];
		$pipes = $this->pipes;
		//mark as reading
		$this->reading = true;
		//start loop
		while($pipes) {
			//loop through pipes
			foreach($pipes as $key => $pipe) {
				//check process status
				$status = proc_get_status($this->processes[$key]);
				//process done?
				if(!$status || !$status['running']) {
					unset($pipes[$key]);
					continue;
				}
				//add result item?
				if(!isset($result[$key])) {
					$result[$key] = [
						'success' => true,
						'output' => '',
						'error' => '',
					];
				}
				//read output
				while($str = fread($pipe[1], 1024)) {
					$result[$key]['output'] .= $str;
				}
				//check for errors
				while($err = fread($pipe[2], 1024)) {
					$result[$key]['error'] .= $err;
					$result[$key]['success'] = false;
				}
			}
		}
		//close pipes
		$this->close();
		//reset flag
		$this->reading = false;
		//return result
		return (count($result) > 1) ? $result : ($result ? $result[0] : null);
	}

	public function write($content) {
		//has content?
		if(is_string($content) && strlen($content) > 0) {
			//loop through pipes
			foreach($this->pipes as $pipes) {
				fwrite($pipes[0], $content);
				fclose($pipes[0]);
			}
		}
		//chain it
		return $this;
	}

	public function close() {
		//close processes
		foreach(array_keys($this->processes) as $key) {
			//close pipe
			fclose($this->pipes[$key][1]);
			//close process
			proc_close($this->processes[$key]);
		}
		//reset props
		$this->pipes = [];
		$this->processes = [];
		//chain it
		return $this;
	}

}