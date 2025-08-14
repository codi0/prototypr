<?php

namespace Proto2\Security;

class Escaper {

	protected $rules = [];
	protected static $globalRules = [];

	public function __construct(array $opts=[]) {
		//loop through opts
		foreach($opts as $k => $v) {
			//is static property?
			if(in_array($k, [ 'globalRules' ])) {
				self::$$k = $v;
			} else if(property_exists($this, $k)) {
				$this->$k = $v;
			}
		}
	}

	public function addRule($rule, $callback, $global=true) {
		//add rule
		if($global) {
			self::$globalRules[$rule] = $callback;
		} else {
			$this->rules[$rule] = $callback;
		}
		//chain it
		return $this;
	}

	public function escape($value, $rules) {
		//rules to array?
		if(!is_array($rules)) {
			$rules = array_map('trim', explode('|', $rules));
		}
		//loop through rules
		foreach($rules as $rule) {
			//set vars
			$cb = null;
			$params = [];
			//has params?
			if(preg_match('/^(.*)\((.*)\)$/', $rule, $match)) {
				$rule = $match[1];
				$params = array_map('trim', explode(',', $match[2]));
			}
			//find callback
			if(isset($this->rules[$rule])) {
				$cb = $this->rules[$rule];
			} else if(isset(self::$globalRules[$rule])) {
				$cb = self::$globalRules[$rule];
			} else if(method_exists($this, '_rule' . ucfirst($rule))) {
				$cb = [ $this, '_rule' . ucfirst($rule) ];
			} else if(is_callable($rule)) {
				$cb = $rule;
			}
			//valid callback?
			if(!$cb || !is_callable($cb)) {
				throw new \Exception($rule . ' rule does not exist');
			}
			//execute callback
			$value = $this->execCallback($cb, $value, $params);
		}
		//return
		return $value;
	}

	protected function execCallback($cb, $value, $params) {
		//is array?
		if(!is_array($value)) {
			return call_user_func($cb, $value, $params, $this);
		}
		//loop through array
		foreach($value as $k => $v) {
			$value[$k] = $this->execCallback($cb, $v, $params);
		}
		//return
		return $value;
	}

	protected function _ruleHtml($value, array $params) {
		return htmlspecialchars($value ?: '', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8', true);
	}

	protected function _ruleAttr($value, array $params) {
		return preg_replace_callback('/[^a-z0-9,\.\-_]/iSu', function($matches) {
			$chr = $matches[0];
			$ord = ord($chr);
			if(($ord <= 0x1f && $chr != "\t" && $chr != "\n" && $chr != "\r") || ($ord >= 0x7f && $ord <= 0x9f)) {
				return '&#xFFFD;'; //replacement for undefined characters in html
			}
			$ord = hexdec(bin2hex($chr));
			if($ord > 255) {
				return sprintf('&#x%04X;', $ord);
			} else {
				return sprintf('&#x%02X;', $ord);
			}
		}, $value);
	}

	protected function _ruleJs($value, array $params) {
		return preg_replace_callback('/[^a-z0-9,\._]/iSu', function($matches) {
			$chr = $matches[0];
			if(strlen($chr) == 1) {
				return sprintf('\\x%02X', ord($chr));
			}
			$hex = strtoupper(bin2hex($chr));
			if(strlen($hex) <= 4) {
				return sprintf('\\u%04s', $hex);
			} else {
				return sprintf('\\u%04s\\u%04s', substr($hex, 0, 4), substr($hex, 4, 4));
			}
		}, $value);
	}

	protected function _ruleCss($url, array $params) {
		return preg_replace_callback('/[^a-z0-9]/iSu', function($matches) {
			$chr = $matches[0];
			if(strlen($chr) == 1) {
				$ord = ord($chr);
			} else {
				$ord = hexdec(bin2hex($chr));
			}
			return sprintf('\\%X ', $ord);	
		}, $value);
	}

	protected function _ruleUrl($value, array $params) {
		if(strpos($value, '?') !== false) {
			$url = explode('?', $value, 2);
			parse_str($url[1], $arr);
			return $url[0] . ($arr ? '?' . http_build_query($arr, '', '&amp;', PHP_QUERY_RFC3986) : '');
		} else {
			return rawurlencode($value);
		}
	}

}