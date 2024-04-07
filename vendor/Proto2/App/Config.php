<?php

namespace Proto2\App;

//PSR-11 compatible
class Config {

	protected $idToken = '.';
	protected $paramToken = '%';
	protected $pathCheck = '/config/';

	protected $data = [];
	protected $pathsLoaded = [];

	public function __construct(array $data = []) {
		//merge data
		foreach(func_get_args() as $data) {
			$this->merge($data);
		}
	}

	public function toArray() {
		return $this->formatData($this->data);
	}

	public function has($id) {
		return $this->get($id) !== null;
	}

	public function get($id, $default=null) {
		//set vars
		$data = $this->data;
		$segments = $id ? explode($this->idToken, $id) : [];
		//loop through segments
		foreach($segments as $seg) {
			//has data?
			if($data) {
				//get type
				$type = gettype($data);
				//is array?
				if($type === 'array' || $data instanceof \ArrayAccess) {
					//key exists?
					if(isset($data[$seg])) {
						$data = $data[$seg];
						continue;
					}
				} else if($type === 'object') {
					//call method?
					if(preg_match('/^(\w+)\(([^\)]+)?\)$/', $seg, $match)) {
						//update segment
						$seg = $match[1];
						$params = isset($match[2]) ? array_map('trim', explode(',', $match[2])) : [];
						//call method?
						if(is_callable([ $data, $seg ])) {
							$data = $data->$seg(...$params);
							continue;
						}
					} else if(property_exists($data, $seg)) {
						$data = $data->$seg;
						continue;
					}
				}
			}
			//not found
			return $default;
		}
		//return
		return $this->formatData($data);
	}

	public function set($id, $value, $replace=true) {
		//update root?
		if(empty($id)) {
			//update root
			$this->data = $value ? $this->arrayMergeRecursive($this->data, (array) $value) : [];
			//return
			return $this->data;
		}
		//set vars
		$data =& $this->data;
		$segments = explode($this->idToken, $id);
		$count = count($segments);
		//loop through segments
		foreach($segments as $index => $seg) {
			//create segment?
			if(!isset($data[$seg])) {
				$data[$seg] = [];
			}
			//last segment?
			if($count == $index+1) {
				//process data
				if($value === null) {
					//delete
					unset($data[$seg]);
				} else if(is_callable($value)) {
					//callback
					$data[$seg] = $value($data[$seg]);
				} else if($replace || !is_array($data[$seg])) {
					//replace
					$data[$seg] = $value;
				} else {
					//merge
					$data[$seg] = $this->arrayMergeRecursive($data[$seg], (array) $value);
				}
				//return
				return isset($data[$seg]) ? $data[$seg] : null;
			}
			//next segment
			$data =& $data[$seg];
		}
		//failed
		return null;
	}

	public function merge($id, $data=null) {
		//data is null?
		if($data === null) {
			$data = $id;
			$id = null;
		}
		//return
		return $this->set($id, $data, false);
	}

	public function delete($id) {
		return $this->set($id, null);
	}

	public function clear() {
		return $this->set(null, []);
	}

	public function mergeFile($path) {
		//set vars
		$path = $this->formatPath($path);
		//already loaded?
		if(in_array($path, $this->pathsLoaded)) {
			return true;
		}
		//add to array
		$this->pathsLoaded[] = $path;
		//is dir?
		if(strpos($path, '.') === false) {
			$path = rtrim($path, '/') . '/*.php';
		}
		//loop through files
		foreach(glob($path) as $file) {
			//load data
			$data = $this->loadFileContents($file);
			//merge data?
			if($data && is_array($data)) {
				$this->merge($data);
			}
		}
		//return
		return true;
	}

	public function updateFile($path, \Closure $callback) {
		//set vars
		$data = null;
		$source = [];
		$path = $this->formatPath($path);
		//get file?
		if(is_file($path)) {
			$source = $this->loadFileContents($path);
		}
		//run callback?
		if(is_array($source)) {
			$callback = \Closure::bind($callback, $this, $this);
			$data = $callback($source);
		}
		//valid array?
		if(!is_array($data)) {
			return false;
		}
		//save data
		return $this->saveFileContents($path, $data);
	}

	protected function formatData($data) {
		//has data?
		if($data) {
			//get type
			$type = gettype($data);
			//is string?
			if($type === 'string') {
				//get regex
				$regex = $this->paramToken . '([a-z0-9\-\_\.]+)' . $this->paramToken;
				//match found?
				if(preg_match('/' . $regex . '/iU', $data, $match)) {
					//get config value
					$val = $this->get($match[1]);
					//exact match?
					if($data === $match[0]) {
						$data = $val;
					} else {
						$data = str_replace($match[0], $val ?: '', $data);
					}
					//test again
					return $this->formatData($data);
				}
			}
			//is array?
			if($type === 'array') {
				//loop through array
				foreach($data as $k => $v) {
					$data[$k] = $this->formatData($v);
				}
			}
		}
		//return
		return $data;
	}

	protected function formatPath($path) {
		//format path
		$path = str_replace('\\', '/', $path);
		//is name?
		if($this->pathsLoaded && strpos($path, '/') === false) {
			$path = dirname($this->pathsLoaded[0]) . '/' . $path . '.php';
		}
		//valid path?
		if($this->pathCheck && strpos($path, $this->pathCheck) === false) {
			throw new \Exception("Config file path must contain {$this->pathCheck}");
		}
		//return
		return $path;
	}

	protected function loadFileContents($path) {
		return include($path);
	}

	protected function saveFileContents($path, array $data) {
		//format data
		$data = '<?php' . "\n\n" . 'return ' . var_export($data, true) . ';';
		//return
		return file_put_contents($path, $data, LOCK_EX) !== false;
	}

	protected function arrayMergeRecursive(array $arr1, array $arr2) {
		//source empty?
		if(empty($arr1)) {
			return $arr2;
		}
		//loop through 2nd array
		foreach($arr2 as $k => $v) {
			//add to array?
			if(is_numeric($k)) {
				$arr1[] = $v;
				continue;
			}
			//recursive merge?
			if(isset($arr1[$k]) && is_array($arr1[$k]) && is_array($v)) {
				$arr1[$k] = $this->arrayMergeRecursive($arr1[$k], $v);
				continue;
			}
			//update value?
			if($v !== null) {
				//set
				$arr1[$k] = $v;
			} elseif(isset($arr1[$k])) {
				//delete
				unset($arr1[$k]);
			}
		}
		//return
		return $arr1;
	}

}