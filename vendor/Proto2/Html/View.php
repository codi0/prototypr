<?php

namespace Proto2\Html;

class View {

	protected $__calls = [];
	protected $__run = false;

	protected $config;
	protected $router;
	protected $escaper;
	protected $helpers;
	protected $eventManager;

	protected $data = [];
	protected $queue = [
		'canonical' => [],
		'manifest' => [],
		'favicon' => [],
		'css' => [],
		'js' => [],
	];
	protected $queuePos = [
		'css' => 'head',
		'js' => 'head',
	];

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

	public function __call($method, array $args) {
		//method found?
		if(!isset($this->__calls[$method])) {
			throw new \Exception("Method $method not found");
		}
		//return
		return call_user_func_array($this->__calls[$method], $args);
	}

	public function __extend($method, $callable, $ctx=true) {
		//bind closure?
		if($ctx && ($callable instanceof \Closure)) {
			$ctx = is_object($ctx) ? $ctx : $this;
			$callable = \Closure::bind($callable, $ctx, $ctx);
		}
		//set property
		$this->__calls[$method] = $callable;
		//chain it
		return $this;
	}

	public function addData($key, $val) {
		//set vars
		$data =& $this->data;
		$segments = explode('.', $key);
		$count = count($segments);
		//loop through segments
		foreach($segments as $index => $seg) {
			//create segment?
			if(!isset($data[$seg])) {
				$data[$seg] = [];
			}
			//last segment?
			if($count == $index+1) {
				$data[$seg] = $val;
				break;
			}
			//next segment
			$data =& $data[$seg];
		}
		//chain it
		return $this;
	}

	public function setQueuePosition(array $meta) {
		//merge property
		$this->queuePos = array_merge($this->queuePos, $meta);
		//chain it
		return $this;
	}

	public function queue($type, $content, array $dependencies=[], array $opts=[]) {
		//valid type?
		if(!isset($this->queue[$type])) {
			throw new \Exception("Asset queue only supports the following types: " . implode(', ', array_keys($this->queue)));
		}
		//set vars
		$html = '';
		$id = md5($content);
		$url = $this->url($content);
		$ext = $url ? strtolower(pathinfo(explode('?', $url)[0], PATHINFO_EXTENSION)) : '';
		//format opts
		$opts = array_merge([
			'defer' => null,
			'prepend' => false,
			'position' => null,
		], $opts);
		//set defer default?
		if($opts['defer'] === null) {
			$opts['defer'] = ($type === 'js');
		}
		//is canonical?
		if($type === 'canonical') {
			if($url) {
				$html = '<link rel="canonical" href="' . $url . '">';
			}
		}
		//is manifest?
		if($type === 'manifest') {
			if($url) {
				$html = '<link rel="manifest" href="' . $url . '">';
			}
		}
		//is favicon?
		if($type === 'favicon') {
			if($url) {
				$html = '<link rel="icon" type="image/' . $ext . '" href="' . $url . '">';
			}
		}
		//is css?
		if($type === 'css') {
			if($url) {
				$html = '<link rel="stylesheet" href="' . $url . '">';
			} else {
				$html = '<style>' . strip_tags($content) . '</style>';
			}
		}
		//is js?
		if($type === 'js') {
			if($url) {
				$html = '<script' . ($opts['defer'] ? ' defer' : '') . ' src="' . $url . '"></script>';
			} else {
				$html = '<script' . ($opts['defer'] ? ' type="module"' : '') . '>' . strip_tags($content) . '</script>';
			}
		}
		//format item
		$item = [
			'html' => $html,
			'deps' => $dependencies,
			'position' => $opts['position'],
		];
		//add to array
		if($opts['prepend']) {
			$this->queue[$type] = [ $id => $item ] + $this->queue[$type];
		} else {
			$this->queue[$type][$id] = $item;
		}
		//return
		return $id;
	}

	public function dequeue($type, $id) {
		//unqueue asset?
		if(isset($this->queue[$type]) && isset($this->queue[$type][$id])) {
			unset($this->queue[$type][$id]);
		}
	}

	public function has($name) {
		//add default ext?
		if(strpos($name, '.') === false) {
			$name .= '.tpl';
		}
		//get path
		$path = $this->helpers->path($name);
		//return
		return file_exists($path);
	}

	public function tpl($name, array $data=[], $isPrimary=null) {
		//set vars
		$route = $name;
		$themePath = '';
		$tplPath = $name;
		//check primary?
		if($isPrimary === null) {
			$isPrimary = !$this->__run;
			$this->__run = true;
		}
		//is primary?
		if($isPrimary) {
			//set defaults
			$data = array_merge([
				'js' => [],
				'meta' => [],
				'assets' => [],
				'theme' => $this->config->get('theme'),
				'template' => $name,
			], $data);
			//set theme path?
			if($data['theme']) {
				//try path
				$themePath = $this->config->get('paths.modules') . '/' . $data['theme'];
				//path exists?
				if(!is_dir($themePath)) {
					$themePath = '';
				}
			}
			//loop through default config vars
			foreach([ 'urls.base', 'env', 'name' ] as $param) {
				//get value
				$val = $this->config->get($param);
				//set value?
				if($val !== null) {
					$key = str_replace('urls.base', 'baseUrl', $param);
					$data['js'][$key] = $val;
				}
			}
			//get route?
			if($this->router) {
				//get last request
				if($request = $this->router->dispatchStack('last')) {
					//get last route
					if($r = $request->getAttribute('route')) {
						$route = $data['js']['route'] = $r->getName();
						$route = trim(preg_replace('/\/:([a-z0-9]+)(\?)?/i', '', $route));
					}
				}
			}
			//has noindex?
			if(!isset($data['meta']['noindex']) && $this->config->get('env') !== 'prod') {
				$data['meta']['noindex'] = true;
			}
			//set default title?
			if(!isset($data['meta']['title']) || !$data['meta']['title']) {
				if($route) {
					$data['meta']['title'] = str_replace([ '/', '-', '_' ], ' ', ucfirst($route));
				}
			}
			//set body classes?
			if(!isset($data['meta']['body_classes'])) {
				$data['meta']['body_classes'] = [];
			}
			//add default body classes
			foreach([ 'page', $name, $route ] as $cls) {
				//format class
				$cls = trim(str_replace([ ' ', '_', '/' ], '-', strtolower($cls)));
				//add to array?
				if(!empty($cls)) {
					$data['meta']['body_classes'][] = $cls;
				}
			}
			//format as string
			$data['meta']['body_classes'] = implode(' ', array_unique($data['meta']['body_classes']));
			//use theme?
			if($themePath) {
				//update paths
				$tplPath = $themePath . '/layout.tpl';
				$fnPath = $themePath . '/functions';
				//load functions?
				if(is_dir($fnPath)) {
					//create recursive iterator
					$dir = new \RecursiveDirectoryIterator($fnPath);
					$iterator = new \RecursiveIteratorIterator($dir);
					$matches = new \RegexIterator($iterator, '/\.php$/', \RecursiveRegexIterator::MATCH);
					//loop through matches
					foreach($matches as $file) {
						require_once($file);
					}
				}
			}
			//queue custom assets
			foreach($data['assets'] as $asset) {
				//file extension found?
				if($ext = pathinfo($asset, PATHINFO_EXTENSION)) {
					$this->queue($ext, $asset);
				}
			}
			//view.ready event?
			if($this->eventManager) {
				$this->eventManager->dispatch('view.ready', [
					'view' => $this,
				]);
			}
		}
		//add default ext?
		if(strpos($tplPath, '.') === false) {
			$tplPath .= '.tpl';
		}
		//path found?
		if(!$tplPath = $this->helpers->path($tplPath)) {
			throw new \Exception($themePath ? "Theme layout.tpl not found" : "Template $name not found");
		}
		//merge data
		foreach($data as $k => $v) {
			if(is_array($v) && isset($this->data[$k]) && is_array($this->data[$k])) {
				$this->data[$k] = array_merge($this->data[$k], $v);
			} else {
				$this->data[$k] = $v;
			}
		}
		//view closure
		$fn = function($__tpl, $tpl) {
			include($__tpl);
		};
		//buffer
		ob_start();
		//load view
		$fn($tplPath, $this);
		//get html
		$html = ob_get_clean();
		//is primary?
		if($isPrimary) {
			//queue html
			$head = '';
			$body = '';
			//add page data script
			$this->queue('js', '<script>window.pageData = ' . json_encode($this->esc($data['js'])) . ';</script>', [], [
				'defer' => false,
				'prepend' => true,
				'position' => 'head',
			]);
			//add queued asset types
			foreach($this->queue as $type => $items) {
				//loop through items
				foreach($items as $id => $item) {
					//set default position?
					if(!$item['position']) {
						$item['position'] = isset($this->queuePos[$type]) && $this->queuePos[$type] ? $this->queuePos[$type] : 'head';
					}
					//TO-DO: Resolve dependencies
					if($item['position'] == 'body') {
						$body .= $item['html'] . "\n";
					} else {
						$head .= $item['html'] . "\n";
					}
				}
			}
			//add to head?
			if(!empty($head)) {
				$html = str_replace('</head>', $head . '</head>', $html);
			}
			//add to body?
			if(!empty($body)) {
				$html = str_replace('</body>', $body . '</body>', $html);
			}
			//view.html event?
			if($this->eventManager) {
				$html = $this->eventManager->dispatch('view.html', [
					'html' => $html,
					'view' => $this,
				])->html;
			}
		}
		//display
		echo $html;
	}

	public function data($key, $esc='html') {
		//set vars
		$data = $this->data;
		$parts = explode('.', $key);
		//loop through parts
		foreach($parts as $i => $part) {
			//is config?
			if(!$i && $part === 'config') {
				$data = $this->config->toArray();
				continue;
			}
			//data exists?
			if(is_object($data)) {
				//parse method
				$exp = explode('(', $part, 2);
				$method = trim($exp[0]);
				//parse args
				$args = isset($exp[1]) ? trim(trim($exp[1], ')')) : [];
				$args = $args ? array_map('trim', explode(',', $args)) : [];
				//get data
				if(strpos($part, '(') > 0 && method_exists($data, $method)) {
					$data = $data->$method(...$args);
				} else if(isset($data->$part)) {
					$data = $data->$part;
				} else {
					$data = null;
					break;
				}
			} else {
				//get data
				if(isset($data[$part])) {
					$data = $data[$part];
				} else {
					$data = null;
					break;
				}
			}
		}
		//escape?
		if($esc && $data) {
			$data = $this->esc($data, $esc);
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
		return $this->helpers->url($url, $opts);
	}

	public function esc($value, $type='html') {
		//use raw?
		if(!$type || $type === 'raw') {
			return $value;
		}
		//use escaper
		return $this->escaper->escape($value, $type);
	
	}

}