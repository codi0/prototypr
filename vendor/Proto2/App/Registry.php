<?php

namespace Proto2\App;

//PSR-11 compatible
class Registry {

	protected $cached = [];
	protected $closures = [];
	protected $key = 'registry.{key}';

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

	public function has($key) {
		//format key
		$key = explode('.', $key)[0];
		//is closure?
		if(isset($this->closures[$key])) {
			return true;
		}
		//is cached?
		if(isset($this->cached[$key])) {
			return true;
		}
		//has config?
		if($this->config('has', $key)) {
			return true;
		}
		//not found
		return false;
	}

	public function get($key) {
		//format key
		$props = explode('.', $key);
		$key = array_shift($props);
		//create entity?
		if(!isset($this->cached[$key])) {
			$this->cached[$key] = $this->create($key);
		}
		//return
		return $this->resolveProps($this->cached[$key], $props);
	}

	public function set($key, $val) {
		//is callable?
		if(is_callable($val)) {
			$this->closures[$key] = $val;
			return true;
		}
		//is object?
		if(is_object($val)) {
			$this->cached[$key] = $val;
			$this->config('set', "$key.class", get_class($val));
			return true;
		}
		//is class?
		if(is_string($val) && class_exists($val)) {
			$this->config('set', "$key.class", $val);
			return true;
		}
		//invalid input
		throw new \Exception("$key must be a callable, object or class name");
	}

	public function delete($key) {
		//delete from closures?
		if(isset($this->closures[$key])) {
			unset($this->closures[$key]);
		}
		//delete from cache?
		if(isset($this->cached[$key])) {
			unset($this->cached[$key]);
		}
		//return
		return true;
	}

	public function create($key, array $params=[], $merge=true) {
		//format key
		$props = explode('.', $key);
		$key = array_shift($props);
		//get config vars
		$config = $this->config('get', $key);
		$class = $config['class'];
		$args = $config['args'];
		//check composer?
		if($config['composer'] && isset($this->cached['composer'])) {
			//loop through packages
			foreach($config['composer'] as $package) {
				$this->cached['composer']->requirePackage($package);
			}
		}
		//merge params into args
		foreach($params as $k => $v) {
			//merge array?
			if($merge && isset($args[$k]) && is_array($args[$k]) && is_array($v)) {
				$args[$k] = array_merge($args[$k], $v);
			} else {
				$args[$k] = $v;
			}
		}
		//autowire args?
		if(!empty($class)) {
			$args = $this->autowire($class, $args);
		}
		//resolve dependencies
		$args = $this->resolveDeps($args);
		//get entity
		if(isset($this->closures[$key])) {
			$result = call_user_func($this->closures[$key], $args, $config['class']);
		} else if($class) {
			$args = array_values($args);
			$result = new $class(...$args);
		} else {
			throw new \Exception("$key not found in the registry");
		}
		//return
		return $this->resolveProps($result, $props);
	}

	protected function config($method) {
		//set vars
		$res = null;
		$params = func_get_args();
		//remove first param
		array_shift($params);
		//build config key
		$params[0] = str_replace('{key}', $params[0], $this->key);
		//check config?
		if(isset($this->cached['config'])) {
			$res = $this->cached['config']->$method(...$params);
		}
		//is get method?
		if($method === 'get') {
			//create array?
			if(!is_array($res)) {
				$res = [];
			}
			//merge defaults
			$res = array_merge([
				'class' => null,
				'args' => [],
				'composer' => [],
				'autoload' => null,
			], $res);
			//use opts as args?
			if(isset($res['opts'])) {
				$res['args']['opts'] = $res['opts'];
				unset($res['opts']);
			}
		}
		//return
		return $res;
	}

	protected function autowire($class, array $args) {
		return $args;
	}

	protected function resolveDeps(array $args) {
		//loop through args
		foreach($args as $k => $v) {
			//is recursive?
			if($v && is_array($v)) {
				$args[$k] = $this->resolveDeps($v);
				continue;
			}
			//is string?
			if($v && is_string($v)) {
				//get dep key
				$dep = trim($v, '[]');
				//is service?
				if($v === "[$dep]") {
					//get method
					$method = 'get';
					$exp = explode(':', $dep);
					//use create method?
					if(isset($exp[1])) {
						$method = ($exp[1] === 'create') ? 'create' : 'get';
						$dep = $exp[0];
					}
					//get dependency
					$depProps = explode('.', $dep);
					$dep = array_shift($depProps);
					//resolve dependency
					$args[$k] = $this->$method($dep);
					//resolve parts
					$args[$k] = $this->resolveProps($args[$k], $depProps);
				}
			}
		}
		//return
		return $args;
	}

	protected function resolveProps($entity, array $props) {
		//loop through orops
		foreach($props as $p) {
			//stop here?
			if(!$p || !is_object($entity)) {
				break;
			}
			//remove method
			$p = str_replace('()', '', $p);
			//is callable?
			if(is_callable([ $entity, $p ])) {
				$entity = $entity->$p();
			} else if(isset($entity->$p)) {
				$entity = $entity->$p;
			} else {
				$entity = null;
			}
		}
		//return
		return $entity;
	}

}