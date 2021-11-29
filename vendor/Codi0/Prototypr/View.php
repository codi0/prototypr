<?php

namespace Codi0\Prototypr;

class View {

	private $app;
	private $data = [];

	public function __construct($app) {
		$this->app = $app;
	}

	public function __call($method, array $args=[]) {
		//is url?
		if($method === 'url') {
			//format args
			$args = array(
				0 => isset($args[0]) ? $args[0] : '',
				1 => array_merge([ 'time' => true ], (isset($args[1]) ? $args[1] : [])),
			);
		}
		//allowed app method?
		if(in_array($method, [ 'url', 'clean', 'event' ])) {
			return call_user_func_array([ $this->app, $method ], $args);
		}
		//helper method?
		if($helper = $this->app->helper($method)) {
			return call_user_func_array($helper, $args);
		}
		//not found
		throw new \Exception("Template helper method not found: $method");
	}

	public function template($name, array $data=[]) {
		//build path
		$path = $this->app->config('baseDir') . '/templates/' . $name . '.php';
		//file exists?
		if(!is_file($path)) {
			throw new \Exception("Template $name not found");
		}
		//merge data
		$this->data = array_merge($this->data, $data);
		//view closure
		$fn = function($__path, $tpl) {
			include($__path);
		};
		//load view
		$fn($path, $this);
	}

	public function data($key, $clean='html') {
		//set vars
		$data = $this->data;
		$parts = explode('.', $key);
		//loop through parts
		foreach($parts as $i => $part) {
			//is config?
			if(!$i && $part === 'config') {
				$data = $this->app->config();
				continue;
			}
			//data exists?
			if(is_object($data)) {
				if(isset($data->$part)) {
					$data = $data->$part;
				} else {
					$data = null;
					break;
				}
			} else {
				if(isset($data[$part])) {
					$data = $data[$part];
				} else {
					$data = null;
					break;
				}
			}
		}
		//clean data?
		if($data) {
			$data = $this->app->clean($data, $clean);
		}
		//return
		return $data;
	}

}