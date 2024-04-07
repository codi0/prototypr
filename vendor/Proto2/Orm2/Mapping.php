<?php

namespace Proto2\Orm2;

class Mapping {

	protected $cache = [];
	protected $prefix = 'orm';

	protected $config;
	protected $metaCallback = 'Proto2\Reflection\Meta::parse';

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

	public function load($class) {
		//in cache?
		if(!isset($this->cache[$class])) {
			//get annotations
			$annotations = $this->getAnnotations($class);
			//get config
			$config = $this->getConfig($class);
			//merge
			$this->cache[$class] = $this->arrayMergeRecursive($annotations, $config);
		}
		//return
		return $this->cache[$class];
	}

	protected function getAnnotations($class) {
		//meta data
		$meta = [
			'id' => 'id',
			'table' => '',
			'ignore' => [],
			'properties' => [],
			'relations' => [],
		];
		//default rel
		$defRel = [
			'model' => null,
			'where' => [],
			'type' => 'hasOne',
			'lazy' => true,
			'onSet' => true,
			'onValidate' => true,
			'onSave' => true,
			'onDelete' => true,
			'skipFields' => [],
			'if' => [],
		];
		//parse class meta data
		$parse = call_user_func($this->metaCallback, $class);
		//get annotations key
		$annoKey = (isset($parse['attributes']) && $parse['attributes']) ? 'attributes' : 'annotations';
		//loop through annotations
		foreach($parse[$annoKey] as $param => $args) {
			//set property meta?
			if(isset($meta[$param])) {
				//cast type?
				if(is_string($meta[$param])) {
					$args = implode(',', $args);
				} else if(is_bool($meta[$param])) {
					$args = (!$args || $args[0] !== 'false');
				}
				//set param?
				if(!empty($args)) {
					$meta[$param] = $args;
				}
			}
		}
		//loop through properties
		foreach($parse['properties'] as $name => $prop) {
			//skip property?
			if($prop['static']) {
				continue;
			}
			//add to meta data
			$meta['properties'][$name] = [
				'type' => $prop['type'],
				'value' => $prop['value'],
				'null' => true,
				'filters' => [],
				'rules' => [],
			];
			//loop through property annotations
			foreach($prop[$annoKey] as $param => $args) {
				//is relation?
				if($param === 'relation') {
					$defRel['model'] = $param;
					$meta['relations'][$name] = array_merge($defRel, $args);
					unset($meta['props'][$name]);
					break;
				}
				//set property meta?
				if(isset($meta['props'][$name][$param])) {
					$meta['props'][$name][$param] = $args;
				}
			}
		}
		//return
		return $meta;
	}

	protected function getConfig($class) {
		//set vars
		$res = [];
		//has config?
		if($this->config) {
			//get config data
			$conf = $this->config->get($this->prefix) ?: [];
			//find by class
			if(isset($conf[$class])) {
				//exact match
				$res = $conf[$class];
			} else {
				//loop through data
				foreach($conf as $k => $v) {
					//class found?
					if(isset($v['class']) && $v['class'] === $class) {
						$res = $v;
						break;
					}
				}
			}
		}
		//return
		return $res;
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