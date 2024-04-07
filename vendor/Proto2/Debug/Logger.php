<?php

//PSR-3 compatible (without interfaces)

namespace Proto2\Debug;

class Logger {

	protected $dir = '';
	protected $channel = '';
	protected $defaultChannel = 'errors';

	protected $levels = [
		'emergency',
		'alert',
		'critical',
		'error',
		'warning',
		'notice',
		'info',
		'debug',
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
		//guess directory?
		if(!$this->dir) {
			$this->dir = str_replace('\\', '/', __DIR__);
			$this->dir = explode('/vendor/', $this->dir)[0] . '/logs';
		}
		//valid directory?
		if(!is_dir($this->dir)) {
			throw new \Exception("Invalid log directory");
		}
	}

	public function __call($method, array $args) {
		//set vars
		$message = isset($args[0]) ? $args[0] : '';
		$context = isset($args[1]) ? $args[1] : [];
		//delegate to log
		return $this->log($method, $message, $context);
	}

	public function channel($name) {
		//set channel
		$this->channel = $name;
		//chain it
		return $this;
	}

	public function log($level, $message, array $context = []) {
		//set vars
		$channel = $this->channel ?: $this->defaultChannel;
		$this->channel = '';
		//channel set?
		if(empty($channel)) {
			throw new \Exception("Log channel name required");
		}
		//valid level?
		if(!in_array($level, $this->levels)) {
			throw new \Exception("Invalid log level: $level");
		}
		//valid message?
		if(empty($message)) {
			throw new \Exception("Log message cannot be empty");
		}
		//format message?
		if(is_array($message)) {
			$message = json_encode($message, JSON_UNESCAPED_SLASHES);
		}
		//set context params
		foreach($context as $key => $val) {
			$message = str_replace('{' . $key . '}', print_r($val, true), $message);
		}
		//format context?
		if($context) {
			$context = json_encode($context, JSON_UNESCAPED_SLASHES);
		}
		//build log line
		$logLine = [
			'[' . date('Y-m-d H:i:s') . ' UTC]',
			'[' . $level . ']',
			str_replace([ "\r\n", "\n" ], ' ', trim($message)),
			str_replace([ "\r\n", "\n" ], ' ', trim($context ?: '')),
		];
		//convert to string
		$logLine = trim(implode(' ', $logLine));
		//build file path
		$logFile = $this->dir . '/' . $channel . '.log';
		//log to file
		$res = @file_put_Contents($logFile, $logLine . "\n", LOCK_EX|FILE_APPEND);
		//return
		return ($res !== false);
	}

}