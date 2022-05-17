<?php

namespace Prototypr;

class Test {

	protected $title = '';
	protected $results = [];

	protected $testCbs = [];
	protected $currentTest = '';

	use ConstructTrait;

	protected function onConstruct(array $opts) {
		//get title from sub-class?
		if(!$this->title && get_class($this) !== __CLASS__) {
			$ref = new \ReflectionClass($this);
			$this->title = $ref->getShortName();
		}
	}

	public function getTitle() {
		return $this->title;
	}

	public function getResults() {
		return $this->results;
	}

	public function addTest($name, $callback) {
		//format name
		$name = preg_replace_callback("/(?:^|_)([a-z])/", function($m) {
			return strtoupper($m[1]);
		}, $name);
		//format test prefix
		if(stripos($name, 'test') === 0) {
			$name = lcfirst($name);
		} else {
			$name = 'test' . $name;
		}
		//cache callback
		$this->testCbs[$name] = $callback;
	}

	public function run() {
		//set vars
		$this->currentTest = '';
		//run setup
		$this->setup();
		//run method tests
		foreach(get_class_methods($this) as $method) {
			//is test method?
			if(stripos($method, 'test') === 0) {
				//cache test name
				$this->currentTest = get_class($this) . '::' . $method;
				//run test
				$this->$method();
			}
		}
		//run callback tests
		foreach($this->testCbs as $name => $callback) {
			//cache test name
			$this->currentTest = get_class($this) . '::' . $name;
			//run test
			$callback();
		}
		//reset vars
		$this->currentTest = '';
		//run teardown
		$this->teardown();
		//return
		return $this->results;
	}

	public function display($asHtml = true) {
		//set vars
		$tests = 0;
		$assertions = 0;
		$failures = 0;
		$text = '{tests} tests, {assertions} assertions, {failures} failures' . "\n\n";
		//loop through results
		foreach($this->results as $name => $meta) {
			//update counts
			$tests = $tests + 1;
			$assertions = $assertions + count($meta['assertions']);
			$failures = $failures + $meta['fail'];
			//show test result
			$text .= '<b>' . $tests . '.) ' . $name . ' = ' . ($meta['fail'] ? $meta['fail'] . ' failures' : 'pass') . '</b>' . "\n";
			//show failed assertions
			foreach($meta['assertions'] as $a) {
				if(!$a['pass']) {
					$file = str_replace($this->kernel->config('base_dir'), '', $a['file']);
					$text .= '* ' . $a['function'] . ' - ' . ($a['message'] ?: 'failed') . ' - ' . basename($file) . ':' . $a['line'] . "\n";
				}
			}
			//end
			$text .= "\n";
		}
		//as html?
		if($asHtml) {
			$text = str_replace("\n", "\n<br>\n", trim($text));
		} else {
			$text = strip_tags($text);
		}
		//replace placeholders
		$text = str_replace([ '{tests}', '{assertions}', '{failures}' ], [ $tests, $assertions, $failures ], $text);
		$text = str_replace("\n\n", "\n", $text);
		//return
		return $text;
	}

	public function assertTrue($isPass, $failMessage='') {
		//current test set?
		if(!$this->currentTest) {
			throw new \Exception("Current test name not set");
		}
		//set vars
		$backtrace = [];
		$isPass = ($isPass == true);
		//check backtrace
		foreach(debug_backtrace() as $trace) {
			//is assertion call?
			if(stripos($trace['function'], 'assert') === false) {
				break;
			}
			//update trace
			$backtrace = $trace;
		}
		//assertion data
		$assertion = [
			'pass' => $isPass,
			'message' => $isPass ? '' : $failMessage,
			'function' => $backtrace['function'],
			'file' => $backtrace['file'],
			'line' => $backtrace['line'],
		];
		//create test array?
		if(!isset($this->results[$this->currentTest])) {
			$this->results[$this->currentTest] = [
				'pass' => 0,
				'fail' => 0,
				'assertions' => [],
			];
		}
		//log pass or fail
		$this->results[$this->currentTest][$isPass ? 'pass' : 'fail']++;
		//log assertion
		$this->results[$this->currentTest]['assertions'][] = $assertion;
		//return
		return $assertion;
    }

	public function assertFalse($arg, $message='') {
		return $this->assertTrue($arg == false, $message);
	}

	public function assertEquals($arg1, $arg2, $message='') {
		return $this->assertTrue($arg1 == $arg2, $message);
	}

	public function assertNotEquals($arg1, $arg2, $message='') {
		return $this->assertTrue($arg1 != $arg2, $message);
	}

	public function assertSame($arg1, $arg2, $message='') {
		return $this->assertTrue($arg1 === $arg2, $message);
	}

	public function assertNotSame($arg1, $arg2, $message='') {
		return $this->assertTrue($arg1 !== $arg2, $message);
	}

	public function assertNotEmpty($arg, $message='') {
		return $this->assertTrue(!empty($arg), $message);
	}

	public function assertEmpty($arg, $message='') {
		return $this->assertTrue(empty($arg), $message);
	}

	public function assertInArray($arg, array $arr, $message='') {
		return $this->assertTrue(in_array($arg, $arr), $message);
	}

	public function assertNotInArray($arg, array $arr, $message='') {
		return $this->assertTrue(!in_array($arg, $arr), $message);
	}

	protected function setup() {
		//do nothing
	}

	protected function teardown() {
		//do nothing
	}

	public static function dashboard($testDir, array $actions=[]) {
		//helpers
		$sanitize = function($i) { return htmlspecialchars($i, ENT_QUOTES, 'UTF-8'); };
		//set vars
		$files = [];
		$baseClass = '';
		$test = isset($_GET['test']) ? $sanitize($_GET['test']) : '';
		$action = isset($_GET['action']) ? $sanitize($_GET['action']) : '';
		//get test files
		foreach(glob($testDir . '/*.php') as $file) {
			//add file
			$name = str_replace('.php', '', basename($file));
			$files[$name] = $file;
			//set base class?
			if(empty($baseClass)) {
				$baseClass = explode('/vendor/', $file)[1];
				$baseClass = explode('/', $baseClass);
				unset($baseClass[count($baseClass)-1]);
				$baseClass = implode('\\', $baseClass);
			}
		}
		//execute action?
		if(!empty($action)) {
			echo '<p><a href="?test&action">&laquo; Back to dashboard</a></p>';
			if(isset($actions[$action])) {
				$actions[$action]();
				exit();
			} else {
				echo '<p>Action not found</p>';
				exit();
			}
		}
		//run test?
		if(!empty($test)) {
			echo '<p><a href="?test&action">&laquo; Back to dashboard</a></p>';
			$class = $baseClass . '\\' . $test;
			$obj = new $class;
			$obj->run();
			echo $obj->display();
			exit();
		}
		//tests dashboard
		echo '<h2>Run tests</h2>' ."\n";
		echo '<ol>' . "\n";
		foreach($files as $name => $file) {
			echo '<li><a href="?test=' . $sanitize($name) . '">' . $sanitize($name) . ' &raquo;</a></li>' . "\n";
		}
		echo '</ol>' . "\n";
		//actions menu?
		if(!empty($actions)) {
			echo '<br>' . "\n";
			echo '<h2>Other actions</h2>' ."\n";
			echo '<ol>' . "\n";
			foreach($actions as $name => $callback) {
				$text = ucfirst(str_replace('-', ' ', $name));
				echo '<li><a href="?action=' . $sanitize($name) . '">' . $sanitize($text) . ' &raquo;</a></li>' . "\n";
			}
			echo '</ol>' . "\n";
		}
	}

}