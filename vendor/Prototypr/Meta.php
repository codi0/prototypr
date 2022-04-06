<?php

namespace Prototypr;

class Meta {

	protected static $refCache = [];
	protected static $fileCache = [];

	public static function reflect($entity) {
		//is reflector?
		if($entity instanceof \Reflector) {
			return $entity;
		}
		//is object?
		if(is_object($entity)) {
			return new \ReflectionObject($entity);
		}
		//is static method?
		if(is_string($entity) && strpos($entity, '::') > 0) {
			$entity = explode('::', $entity, 2);
		}
		//is array?
		if(is_array($entity)) {
			$class = is_object($entity[0]) ? 'ReflectionObject' : 'ReflectionClass';
			$ref = new $class($entity[0]);
			return $ref->hasMethod($entity[1]) ? $ref->getMethod($entity[1]) : $ref->getProperty($entity[1]);
		}
		//is function?
		if(function_exists($entity)) {
			return new \ReflectionFunction($entity);
		}
		//is class
		return new \ReflectionClass($entity);
	}

	public static function annotations(\Reflector $ref, $open='[', $close=']') {
		//set vars
		$res = [];
		$docblock = $ref->getDocComment();
		$regex = str_replace([ ':open', ':close' ], [ $open, $close ], "/([a-z0-9]+)\:open([^\:close]+)\:close/i");
		//parse comment
		if($docblock && preg_match_all($regex, $docblock, $matches)) {
			//loop through matches
			foreach($matches[1] as $key => $val) {
				//set vars
				$args = [];
				$param = lcfirst(trim($val));
				$data = trim($matches[2][$key]);
				//is json?
				if($data && $data[0] === '{') {
					//decode json
					$args = json_decode($data, true);
					//is valid?
					if(empty($args)) {
						throw new \Exception("Invalid json for model meta parameter $param");
					}
				} else {
					//split data
					$parts = preg_split('/\s+/', $data);
					$parts = array_map('trim', $parts);
					//process args
					foreach($parts as $k => $v) {
						//remove delims
						$v = trim(trim($v, "|,"));
						//add arg?
						if(!empty($v)) {
							$args[] = $v;
						}
					}
				}
				//add to result
				$res[$param] = $args;
			}
		}
		//return
		return $res;
	}

	public static function closure($callable, $thisArg=null) {
		//is closure?
		if(!($callable instanceof \Closure)) {
			//get reflection object
			$ref = self::reflect($callable);
			//get meta data
			$path = $ref->getFileName();
			$startLine = $ref->getStartLine();
			$endLine = $ref->getEndLine();
			//stop here?
			if(empty($path)) {
				return null;
			}
			//get file contents?
			if(!isset(self::$fileCache[$path])) {
				self::$fileCache[$path] = file($path);
			}
			//extract relevant lines
			$lines = array_slice(self::$fileCache[$path], ($startLine - 1), ($endLine - ($startLine - 1)));
			//format start & end lines
			$lines[0] = 'function(' . explode('(', $lines[0], 2)[1];
			$lines[count($lines)-1] = explode('}', $lines[count($lines)-1], 2)[0] . '}';
			//eval callable
			eval('$callable = ' . implode(PHP_EOL, $lines) . ';');
		}
		//bind $this?
		if($thisArg) {
			$callable = \Closure::bind($callable, $thisArg, $thisArg);
		}
		//return
		return $callable;
	}

	public static function parse($entity, array $opts=[]) {
		//set vars
		$isRef = ($entity instanceof \Reflector);
		$isObj = !$isRef && is_object($entity);
		//default opts
		$opts = array_merge([
			'props' => false,
			'methods' => false,
		], $opts);
		//get cache key
		if($entity instanceof \Reflector) {
			$key = $entity->getName();
		} else if(is_object($entity)) {
			$key = get_class($entity);
		} else {
			$key = $entity;
		}
		//hash key with opts
		$key = md5(json_encode($key) . json_encode($opts));
		//is cached?
		if(!isset(self::$refCache[$key])) {
			//get reflection object
			$ref = self::reflect($entity);
			//build meta
			$meta = self::buildMeta($ref);
			//set name and type
			$meta['name'] = $ref->getName();
			$meta['type'] = lcfirst(str_replace('Reflection', '', get_class($ref)));
			//find props?
			if($opts['props'] && method_exists($ref, 'getProperties')) {
				//set key
				$meta['properties'] = [];
				//loop through props
				foreach($ref->getProperties() as $prop) {
					$meta['properties'][$prop->getName()] = self::buildMeta($prop);
				}
			}
			//find methods?
			if($opts['methods'] && method_exists($ref, 'getMethods')) {
				//set key
				$meta['methods'] = [];
				//loop through methods
				foreach($ref->getMethods() as $method) {
					$meta['methods'][$method->getName()] = self::buildMeta($method);
				}
			}
			//add to cache
			self::$refCache[$key] = $meta;
		}
		//return
		return self::$refCache[$key];
	}

	protected static function buildMeta(\Reflector $ref) {
		//set vars
		$res = [];
		//set scope?
		if(method_exists($ref, 'isPublic')) {
			$res['scope'] = $ref->isPrivate() ? 'private' : ($ref->isProtected() ? 'protected' : 'public');
		}
		//set static?
		if(method_exists($ref, 'isStatic')) {
			$res['static'] = $ref->isStatic();
		}
		//set default value
		if(method_exists($ref, 'getDefaultValue')) {
			$res['value'] = $ref->getDefaultValue();
			$res['type'] = $ref->hasType() ? $ref->getType()->getName() : getType($ref->getDefaultValue());
		}
		//set params?
		if(method_exists($ref, 'getParameters')) {
			//set key
			$res['params'] = [];
			//loop through array
			foreach($ref->getParameters() as $param) {
				//default value
				$defVal = $param->isOptional() ? $param->getDefaultValue() : NULL;
				//create array
				$res['params'][$param->getName()] = [
					'required' => !$param->isOptional(),
					'value' => $defVal,
					'type' => $param->hasType() ? $param->getType()->getName() : getType($defVal),
					'ref' => $param,
				];
			}
		}
		//set attributes?
		if(method_exists($ref, 'getAttributes')) {
			//set key
			$res['attributes'] = [];
			//loop through array
			foreach($ref->getAttributes() as $attr) {
				//create array
				$res['attributes'][$attr->getName()] = [
					'args' => $attr->getArguments(),
					'ref' => $attr,
				];
			}
		}
		//set annotations
		$res['annotations'] = self::annotations($ref);
		//set ref
		$res['ref'] = $ref;
		//return
		return $res;
	}

}