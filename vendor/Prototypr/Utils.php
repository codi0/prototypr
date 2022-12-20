<?php

namespace Prototypr;

class Utils {

	public static function getFromArray(array $arr, $key, array $opts=[]) {
		//default opts
		$opts = array_merge([
			'default' => null,
			'separator' => '.',
		], $opts);
		//format key?
		if(!is_array($key)) {
			$key = $key ? explode($opts['separator'], $key) : [];
		}
		//loop through key
		foreach($key as $k) {
			//is array?
			if(is_array($arr) && isset($arr[$k])) {
				$arr = $arr[$k];
				continue;
			}
			//is object?
			if(is_object($arr) && isset($arr->$k)) {
				$arr = $arr->$k;
				continue;
			}
			//not found
			$arr = $opts['default'];
			break;
		}
		//return
		return $arr;
	}

	public static function addToArray(array $arr, $key, $val, array $opts=[]) {
		//default opts
		$opts = array_merge([
			'separator' => '.',
			'array' => false,
		], $opts);
		//format key?
		if(!is_array($key)) {
			$key = $key ? explode($opts['separator'], $key) : [];
		}
		//set vars
		$tmp =& $arr;
		$count = count($key);
		//loop through key
		foreach($key as $k => $v) {
			//is last segment?
			if($count == ($k+1)) {
				if($opts['array']) {
					$tmp[$v] = isset($tmp[$v]) ? $tmp[$v] : [];
					$tmp[$v][] = $val;
				} else {
					$tmp[$v] = $val;
				}
			} else {
				$tmp[$v] = isset($tmp[$v]) ? $tmp[$v] : [];
				$tmp =& $tmp[$v];
			}
		}
		//return
		return $arr;
	}

}