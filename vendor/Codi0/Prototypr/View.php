<?php

namespace Codi0\Prototypr;

class View {

	private $app;
	private $data = [];

	public function __construct($app, $useTheme=true) {
		$this->app = $app;
		$this->useTheme = (bool) $useTheme;
	}

	public function __call($method, array $args=[]) {
		//helper method?
		if($helper = $this->app->helper($method)) {
			return call_user_func_array($helper, $args);
		}
		//not found
		throw new \Exception("Template helper method not found: $method");
	}

	public function tpl($name, array $data=[], $useTheme=false) {
		//path found?
		if(!$path = $this->app->path($useTheme ? "layout.tpl" : "$name.tpl")) {
			throw new \Exception($useTheme ? "Theme layout not found" : "Template $name not found");
		}
		//merge data
		$this->data = array_merge($this->data, $data);
		//set template path
		$this->data['template'] = $name;
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
			$data = $this->clean($data, $clean);
		}
		//return
		return $data;
	}

	public function url($url='', $opts=[]) {
		//default opts
		$opts = array_merge([
			'time' => true,
		], $opts);
		//return
		return $this->app->url($url, $opts);
	}

	public function clean($value, $type='html') {
		return $this->app->clean($value, $type);
	
	}

}