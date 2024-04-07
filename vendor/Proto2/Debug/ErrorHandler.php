<?php

namespace Proto2\Debug;

class ErrorHandler {

	protected $cli = false;
	protected $debug = false;
	protected $debugBar = true;
	protected $phpErrorLog = true;

	protected $db;
	protected $logger;
	protected $eventManager;
	protected $displayCallback;

	protected $prevHandler;
	protected $startMem = 0;
	protected $startTime = 0;
	protected $handled = false;

	protected $alwaysDisplay = [
		'critical',
		'error',
	];

	protected $errorLevels = [
		E_PARSE => 'critical',
		E_COMPILE_ERROR => 'critical',
		E_CORE_ERROR => 'critical',
		E_ERROR => 'error',
		E_USER_ERROR => 'error',
		E_RECOVERABLE_ERROR => 'error',
		E_WARNING => 'warning',
		E_USER_WARNING => 'warning',
		E_CORE_WARNING => 'warning',
		E_COMPILE_WARNING => 'warning',
		E_NOTICE => 'notice',
		E_USER_NOTICE => 'notice',
		E_DEPRECATED => 'notice',
		E_USER_DEPRECATED => 'notice',
		E_STRICT => 'info',
	];

	public function __construct(array $opts=[], $merge=true) {
		//start timer
		$this->startTime = microtime(true);
		$this->startMem = memory_get_usage();
		//loop through opts
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
		//create logger?
		if(!$this->logger) {
			$this->logger = new Logger;
		}
		//handle events?
		if($this->eventManager) {
			//add http.client listener
			$this->eventManager->add('http.client', [ $this, 'eventHttpClient' ]);
			//add debug listeners?
			if($this->debug && $this->debugBar) {
				$this->eventManager->add('view.html', [ $this, 'eventViewHtml' ]);
				$this->eventManager->add('api.json', [ $this, 'eventApiJson' ]);
			}
		}
	}

	public function handle() {
		//can handle?
		if(!$this->handled) {
			//update flag
			$this->handled = true;
			//report all errors
			error_reporting(E_ALL);
			//do not display errors
			@ini_set('display_errors', 0);
			@ini_set('display_startup_errors', 0);
			//handle exceptions
			$this->prevHandler = set_exception_handler(array( $this, 'handleException' ));
			//handle legacy errors
			set_error_handler(array( $this, 'handleError' ));
			//handle fatal errors
			register_shutdown_function(array( $this, 'handleShutdown' ));
		}
	}

	public function handleException($ex, $display=true) {
		//set opts
		$opts = [
			'ex' => $ex,
			'level' => $this->getExLevel($ex),
			'log' => true,
			'display' => $display,
			'debug' => $this->debug,
		];
		//dispatch event?
		if($this->eventManager) {
			//error handle event
			$e = $this->eventManager->dispatch('error.handle', $opts);
			//merge opts
			$opts = array_merge($opts, $e->getParams());
		}
		//can log?
		if(!$opts['log']) {
			return;
		}
		//build error message
		$errMsg = 'PHP ' . $opts['level'] . ': ' . $opts['ex']->getMessage() . ' in ' . $opts['ex']->getFile() . ' on line ' . $opts['ex']->getLine();
		//custom log
		$this->log($opts['level'], $errMsg);
		//delegate to previous handler?
		if(!$this->debug && $this->prevHandler) {
			return call_user_func($this->prevHandler, $ex);
		}
		//php error log?
		if($this->phpErrorLog) {
			error_log($errMsg);
		}
		//display error?
		if($opts['display'] && ($this->debug || in_array($opts['level'], $this->alwaysDisplay))) {
			//set args
			$args = [ $errMsg, $opts['ex'] ];
			//get output
			if($this->cli) {
				//cli handler
				$output = strip_tags($errMsg);
			} else if($this->debug) {
				//debug handler
				$output = $this->displayError(...$args);
			} else {
				//non-debug handler
				if(!$output = $this->execCallback($opts['display'], $args)) {
					if(!$output = $this->execCallback($this->displayCallback, $args)) {
						$output = '<h1>An error has occurred</h1>';
					}
				}
			}
			//display
			echo $output;
		}
	}

	public function handleError($severity, $message, $file, $line) {
		//convert to exception
		$ex = new \ErrorException($message, 0, $severity, $file, $line);
		//handle exception
		$this->handleException($ex);
	}

	public function handleShutdown() {
		//set vars
		$error = error_get_last();
		$httpCode = http_response_code();
		//log error?
		if($error) {
			//convert to exception
			$ex = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
			//handle exception
			$this->handleException($ex);		
		}
		//log server error?
		if(is_numeric($httpCode) && ($httpCode < 100 || $httpCode >= 500)) {
			//build error message
			$errMsg = 'HTTP SERVER ' . $httpCode;
			//log error
			$this->log('error', $errMsg, true);
		}
	}

	public function eventHttpClient($e) {
		//log failed response?
		if(!$e->code || $e->code >= 400) {
			//build error message
			$error = ($e->message ?: 'Unknown error') . ' (' . $e->code . ') from ' . $e->url;
			//log error
			$this->log('error', "HTTP CLIENT: $error", false);
		}
		//return
		return $e;
	}

	public function eventViewHtml($e) {
		//set vars
		$queryNum = 0;
		$conns = $this->db ? $this->db->getConns() : [];
		$time = number_format(microtime(true) - $this->startTime, 5);
		$mem = number_format((memory_get_usage() - $this->startMem) / 1024, 0);
		$peak = number_format(memory_get_peak_usage() / 1024, 0);
		//count all queries
		foreach($conns as $conn) {
			$queryNum += count($conn['queries']);
		}
		//open wrap
		$html  = '<div id="debug-bar" style="font-size:13px; padding:10px; margin-top:15px; background:#dfdfdf;">';
		//header bar
		$html .= '<div class="heading" onclick="return this.nextSibling.style.display=\'block\';">';
		$html .= '<span style="font-weight:bold;">Debug bar:</span> &nbsp;' . $time . 's &nbsp;|&nbsp; ' . $mem . 'kb &nbsp;|&nbsp; ' . $peak . 'kb peak &nbsp;|&nbsp; ';
		$html .= '<span style="color:blue;cursor:pointer;">' . $queryNum . ' queries &raquo;</span>';
		$html .= '</div>';
		//queries bar?
		if($queryNum > 0) {
			$html .= '<div class="queries" style="display:none;">';
			//loop through conns
			foreach($conns as $key => $conn) {
				$html .= '<div>DB ' . ($key+1) . '</div>';
				$html .= '<ol style="padding-left:20px;">';
				//loop through queries
				foreach($conn['queries'] as $query) {
					$html .= '<li style="margin:8px 0 0 0; line-height:1.1;">' . $query[0] . ' | ' . $query[1] . '</li>';
				}
				$html .= '</ol>';
			}
			$html .= '</div>';
		} else {
			$html .= '<div class="no-queries" style="display:none;">Query log empty</div>';
		}
		//close wrap
		$html .= '</div>';
		//update html
		$e->html = str_replace('</body>', $html . '</body>', $e->html);
		//return
		return $e;
	}

	public function eventApiJson($e) {
		//set vars
		$json = $e->json;
		$dbQueries = $this->db ? $this->db->queries : [];
		//add debug
		$json['debug'] = [
			'time' => number_format(microtime(true) - $this->startTime, 5) . 's',
			'memory' => number_format((memory_get_usage() - $this->startMem) / 1024, 0) . 'kb',
			'memory_peak' => number_format(memory_get_peak_usage() / 1024, 0) . 'kb',
			'queries_num' => count($dbQueries),
			'queries' => $dbQueries,
		];
		//update event
		$e->json = $json;
		//return
		return $e;
	}

	protected function log($level, $message, $extra=true) {
		//can log?
		if(!$this->logger) {
			return false;
		}
		//add to message?
		if($extra !== false) {
			//set vars
			$extra = [ $this->cli ? 'cli' : 'web' ];
			//has host?
			if(isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']) {
				$extra[] = $_SERVER['REQUEST_METHOD'];
				$extra[] = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			}
			//add to message
			$message .= ' (' . implode(' | ', $extra) . ')';
		}
		//add to log
		return $this->logger->$level($message);
	}

	protected function getExLevel($ex) {
		//get severity
		$severity = method_exists($ex, 'getSeverity') ? $ex->getSeverity() : 1;
		//return
		return isset($this->errorLevels[$severity]) ? $this->errorLevels[$severity] : 'error';
	}

	protected function displayError($errMsg, $ex) {
		//set vars
		$html = '';
		//debug output
		$html .= '<div class="err" style="margin:1em 0; padding: 0.5em; border:1px red solid;">';
		$html .= $errMsg;
		$html .= ' [<a href="javascript:void(0);" onclick="this.nextSibling.nextSibling.style.display=\'block\';">show trace</a>]';
		$html .= '<div style="display:none; padding-top:15px;">';
		$html .= str_replace("\n", "<br>", $ex->getTraceAsString());
		$html .= '</div>';
		$html .= '</div>';	
		//return
		return $html;
	}

	protected function execCallback($cb, array $args=[]) {
		//is callable?
		if(is_callable($cb)) {
			ob_start();
			$output = call_user_func_array($cb, $args);
			$buffer = ob_get_clean();
			return $output ?: $buffer;
		}
	}

}