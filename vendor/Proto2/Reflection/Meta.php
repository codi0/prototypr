<?php

namespace Proto2\Reflection;

class Meta {

	protected static $refCache = [];
	protected static $fileCache = [];

	public static function parse($entity, array $opts=[]) {
		//set vars
		$isRef = ($entity instanceof \Reflector);
		$isObj = !$isRef && is_object($entity);
		//default opts
		$opts = array_merge([
			'properties' => true,
			'methods' => false,
			'attributes' => true,
			'annotations' => true,
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
			$meta = self::buildMeta($ref, $opts);
			//set name and type
			$meta['name'] = $ref->getName();
			$meta['type'] = lcfirst(str_replace('Reflection', '', get_class($ref)));
			//find properties?
			if($opts['properties'] && method_exists($ref, 'getProperties')) {
				//set key
				$meta['properties'] = [];
				//loop through properties
				foreach($ref->getProperties() as $prop) {
					$meta['properties'][$prop->getName()] = self::buildMeta($prop, $opts);
				}
			}
			//find methods?
			if($opts['methods'] && method_exists($ref, 'getMethods')) {
				//set key
				$meta['methods'] = [];
				//loop through methods
				foreach($ref->getMethods() as $method) {
					$meta['methods'][$method->getName()] = self::buildMeta($method, $opts);
				}
			}
			//add to cache
			self::$refCache[$key] = $meta;
		}
		//return
		return self::$refCache[$key];
	}

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

	public static function attributes(\Reflector $ref) {
		//set vars
		$res = [];
		//loop through array
		foreach($ref->getAttributes() as $attr) {
			//set vars
			$args = [];
			$name = $attr->getName();
			//remove namespace?
			if(strpos($name, '\\') > 0) {
				$parts = explode('\\', $name);
				$name = $parts[count($parts)-1] ?: $name;
			}
			//format name
			$name = lcfirst(trim($name));
			//loop through args
			foreach($attr->getArguments() as $k => $v) {
				$args[$k] = self::parseArg($v, false);
			}
			//add to result
			$res[$name] = $args ?: [ 'true' ];
		}
		//return
		return $res;
	}

	public static function annotations(\Reflector $ref) {
		//set vars
		$res = [];
		$lines = preg_split("/\r?\n/", trim($ref->getDocComment()));
		//loop through lines
		foreach($lines as $line) {
			//format line
			$line = trim($line, '*@[]() ');
			//match found?
			if(!$line || !preg_match('/([a-z0-9-_]+)[\[\(](.*)/i', $line, $match)) {
				continue;
			}
			//set vars
			$name = lcfirst(trim($match[1]));
			$args = self::parseArg($match[2], true);
			//add to result
			$res[$name] = $args ?: [ 'true' ];
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

	protected static function buildMeta(\Reflector $ref, array $opts=[]) {
		//set vars
		$res = [];
		//format opts
		$opts = array_merge([
			'parameters' => true,
			'attributes' => true,
			'annotations' => true,
		], $opts);
		//set scope?
		if(method_exists($ref, 'isPublic')) {
			$res['scope'] = $ref->isPrivate() ? 'private' : ($ref->isProtected() ? 'protected' : 'public');
		}
		//set static?
		if(method_exists($ref, 'isStatic')) {
			$res['static'] = $ref->isStatic();
		}
		//set default value?
		if($ref instanceof \ReflectionProperty) {
			$res['value'] = $ref->getDeclaringClass()->getDefaultProperties()[$ref->getName()];
			$res['type'] = $ref->hasType() ? $ref->getType()->getName() : getType($res['value']);
		}
		//set parameters?
		if($opts['parameters'] && method_exists($ref, 'getParameters')) {
			//set key
			$res['parameters'] = [];
			//loop through array
			foreach($ref->getParameters() as $param) {
				//default value
				$defVal = $param->isOptional() ? $param->getDefaultValue() : NULL;
				//create array
				$res['parameters'][$param->getName()] = [
					'required' => !$param->isOptional(),
					'value' => $defVal,
					'type' => $param->hasType() ? $param->getType()->getName() : getType($defVal),
					'ref' => $param,
				];
			}
		}
		//set attributes?
		if($opts['attributes'] && method_exists($ref, 'getAttributes')) {
			$res['attributes'] = self::attributes($ref);
		}
		//set annotations?
		if($opts['annotations']) {
			$res['annotations'] = self::annotations($ref);
		}
		//set ref
		$res['ref'] = $ref;
		//return
		return $res;
	}

	protected static function parseArg($input, $split=true) {
		//set vars
		$output = [];
		$input = trim($input);
		$jsonChars = [ '{', '[' ];
		$delims = [ ',', '|', '/s' ];
		//is empty?
		if(!empty($input)) {
			//parse input
			if(in_array($input[0], $jsonChars)) {
				//json
				$output = json_decode($input, true) ?: [];
			} else if((strpos($input, '&') > 0 || strpos($input, '=') > 0) && strpos($input, ' ') === false) {
				//query string
				parse_str($input, $output);
			} else {
				//set delim
				$delim = $split ? '\s' : '';
				//check delims
				foreach($delims as $d) {
					//match found?
					if(strpos($input, $d) > 0) {
						$delim = $d;
						break;
					}
				}
				//has delim?
				if(!empty($delim)) {
					//split to array
					$input = preg_split('/[' . $delim . ']+/', $input);
					//loop through array
					foreach($input as $k => $v) {
						//add to output?
						if($v = trim($v)) {
							$output[] = $v;
						}
					}
				} else {
					//do nothing
					$output = $input;
				}
			}
		}
		//return
		return $output;
	}

}