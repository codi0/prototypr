<?php

namespace Proto2\Html;

class Field {

	protected $helpers;
	protected $captcha;

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
		//set vars
		$name = isset($args[0]) ? $args[0] : '';
		$value = isset($args[1]) ? $args[1] : '';
		$opts = isset($args[2]) ? $args[2] : [];
		//set input type
		$args['type'] = $method;
		//return
		return $this->input($name, $value, $opts);
	}

	public function input($name, $value='', array $opts=[]) {
		//set opts
		$opts = array_merge([
			'type' => 'text',
			'name' => ($name === 'submit') ? '' : $name,
			'value' => $value,
		], $opts);
		//return
		return '<input' . self::formatAttr($opts) . '>';
	}

	public function file($name, $value='', array $opts=[]) {
		//set vars
		$html = '';
		$url = '';
		//set opts
		$opts = array_merge([
			'type' => 'file',
			'name' => $name,
		], $opts);
		//show value?
		if($value && is_file($value)) {
			if($url = $this->helpers->url($value)) {
				$html .= '<span class="current"><a href="' . $url . '" target="_blank">' . $url . '</a> &nbsp; [<a class="replace" href="javascript://" onclick="this.parentNode.nextSibling.style.display=\'block\'; this.parentNode.nextSibling.firstChild.click();">replace</a>]</span>';
			}
		}
		//add input
		$html .= '<span class="upload"' . ($url ? ' style="display:none;"' : '') . '><input' . self::formatAttr($opts) . '></span>';
		//return
		return $html;
	}

	public function password($name, $value='', array $opts=[]) {
		//set vars
		$toggle = false;
		//use toggle?
		if(array_key_exists('toggle', $opts)) {
			$toggle = !!$opts['toggle'];
			unset($opts['toggle']);
		}
		//generate input
		$html = $this->input($name, $value, array_merge([ 'type' => 'password' ], $opts));
		//add toggle?
		if($toggle) {
			$html .= '<span class="show-password" onclick="this.previousSibling.type=(this.previousSibling.type==\'password\')?\'text\':\'password\'"></span>';
		}
		//return
		return $html;
	}

	public function textarea($name, $value='', array $opts=[]) {
		//set opts
		$opts = array_merge([
			'name' => $name,
		], $opts);
		//remove type?
		if(isset($opts['type'])) {
			unset($opts['type']);
		}
		//return
		return '<textarea' . self::formatAttr($opts) . '>' . $value . '</textarea>';
	}

	public function checkbox($name, $value='', array $opts=[]) {
		//format attr
		$opts = array_merge([
			'id' => '',
			'type' => 'checkbox',
			'options' => [],
		], $opts);
		//has options?
		if(!$opts['options']) {
			throw new \Exception(ucfirst($opts['type']) . " field must include options parameter");
		}
		//set vars
		$isMulti = (count($opts['options']) > 1) && ($opts['type'] !== 'radio');
		$value = $isMulti ? ((array) $value ?: []) : ((string) $value ?: '');
		//open html
		$html = '<span class="' . $opts['type'] . '-group">';
		//loop through options
		foreach($opts['options'] as $key => $label) {
			//set vars
			$n = $name;
			$id = ($opts['id'] ? $opts['id'] : $name). '-' . $key;
			//set value
			if($opts['type'] === 'radio') {
				$v = (string) $key;
			} else {
				$v = '1';
			}
			//is array?
			if($isMulti) {
				$n .= '[' . $key . ']';
				$checked = ((isset($value[$key]) && $value[$key]) || $value === $key) ? ' checked' : '';
			} else {
				$checked = ($value === $n || $value === $v) ? ' checked' : '';
			}
			//add html
			$html .= '<span class="' . $opts['type'] . '-wrap">';
			$html .= '<input type="' . $opts['type'] . '" name="' . $n . '" value="' . $v . '" id="' . $id . '"' . $checked . '>';
			$html .= '<label for="' . $id . '">' . ucfirst($label) . '</label>';
			$html .= '</span>' . "\n";
		}
		//close html
		$html .= '</span>' . "\n";
		//return
		return $html;
	}

	public function radio($name, $value='', array $opts=[]) {
		//set type as radio
		$opts['type'] = 'radio';
		//delegate to checkbox method
		return $this->checkbox($name, $value, $opts);
	}

	public function select($name, $value='', array $opts=[]) {
		//format attr
		$opts = array_merge([
			'name' => $name,
			'options' => [],
		], $opts);
		//remove type?
		if(isset($opts['type'])) {
			unset($opts['type']);
		}
		//get options
		$options = $opts['options'];
		unset($opts['options']);
		//set default value?
		if($value === '' || $value === null) {
			$value = array_keys($options);
			$value = $value ? $value[0] : '';
		}
		//open select
		$html = '<select' . self::formatAttr($opts) . '>' . "\n";
		//loop through options
		foreach($options as $key => $val) {
			//standard opt?
			if(!is_array($val)) {
				$html .= '<option value="' . $key . '"' . ($key == $value ? ' selected' : '') . '>' . $val . '</option>' . "\n";
				continue;
			}
			//open opt group
			$html .= '<optgroup label="' . $key . '">' . "\n";
			//loop through options
			foreach($val as $k => $v) {
				$html .= '<option value="' . $k . '"' . ($k == $value ? ' selected' : '') . '>' . $v . '</option>' . "\n";
			}
			//close opt group
			$html .= '</optgroup>' . "\n";
		}
		//close select
		$html .= '</select>';
		//return
		return $html;
	}

	public function nav($name, $value='', array $opts=[]) {
		//open nav
		$html = '<nav id="' . $name . '">' . "\n";
		//add toggle
		$html .= '<div class="icons">' . "\n";
		$html .= '<i class="toggle" onclick="this.parentNode.parentNode.classList.toggle(\'open\');"></i>' . "\n";
		$html .= '</div>' . "\n";
		//open menu
		$html .= '<div class="menu">' . "\n";
		//loop through opts
		foreach($opts as $k => $v) {
			//set vars
			$classes = [];
			$exact = true;
			//is valid?
			if(!$k || !$v) {
				continue;
			}
			//wildcard match?
			if(substr($k, -1) === '*') {
				$exact = false;
				$k = substr($k, 0, -1);
			}
			//is active?
			if(($exact && $k == $value) || (!$exact && strpos($value, $k) !== false)) {
				$classes[] = 'active';
			}
			//create attribute
			$classes = $classes ? ' class="' . implode(' ', $classes) . '"' : '';
			//create url?
			if($this->helpers) {
				$k = $this->helpers->url($k);
			}
			//add link
			$html .= '<a href="' . $k . '"' . $classes . '>' . $v . '</a>' . "\n";
		}
		//close menu
		$html .= '</div>' . "\n";
		//close nav
		$html .= '</nav>' . "\n";
		//return
		return $html;
	}

	public function pagination($current, $total, array $opts=[]) {
		//stop here?
		if($total < 2) {
			return '';
		}
		//set vars
		$html = '';
		$prev = 0;
		$params = $_GET;
		//set opts
		$opts = array_merge([
			'var' => 'p',
			'params' => [],
			'hash' => '',
		], $opts);
		//remove params
		foreach(array_merge($opts['params'], [ $opts['var'] => null ]) as $k => $v) {
			if(isset($params[$k])) {
				unset($params[$k]);
			}
		}
		//build uri
		$uri = explode('?', $_SERVER['REQUEST_URI'])[0] . ($params ? '?' . http_build_query($params) : '');
		$sep = (strpos($uri, '?') !== false) ? '&' : '?';
		//add hashtag?
		if($opts['hash'] && $opts['hash'][0] !== '#') {
			$opts['hash'] = '#' . $opts['hash'];
		}
		//get page numbers
		if($total <= 5) {
			$pages = range(1, $total);
		} else {
			$pages = array_unique([ 1, $current-1, $current, $current+1, $total ]);
		}
		//filter page numbers
		$pages = array_filter($pages, function($item) use($total) {
			return $item >= 1 && $item <= $total;
		});
		//open wrapper
		$html .= '<div class="pagination">' . "\n";
		//loop through pages
		foreach($pages as $p) {
			//build link
			$classes = [];
			$disabled = false;
			$var = ($p == 1) ? [] : [ $opts['var'] => $p ];
			$link = trim($uri . $sep . http_build_query(array_merge($opts['params'], $var)), '?&');
			//disable first page?
			if($p == 1 && $current == 1) {
				$classes[] = 'disabled';
			}
			//disable last page?
			if($p == $total && $current == $total) {
				$classes[] = 'disabled';
			}
			//current page?
			if($p == $current) {
				$classes[] = 'active';
			}
			//add skip?
			if($prev && $prev != ($p-1)) {
				$html .= '<span class="skip">...</span>' . "\n";
			}
			//add html
			$html .= '<a href="' . $link . $opts['hash'] . '"' . ($classes ? ' class="' . implode(' ', $classes) . '"' : '') . '>' . $p . '</a>' . "\n";
			//save prev
			$prev = $p;
		}
		//close wrapper
		$html .= '</div>' . "\n";
		//return
		return $html;	
	}

	public function captcha($name, $value='', array $opts=[]) {
		//set opts
		$opts = array_merge([
			'format' => 'jpeg',
			'raw' => true,
			'reuse' => true,
		]);
		//captcha service available?
		if(!$this->captcha) {
			throw new \Exception('No captcha service available');
		}
		//reuse captcha?
		if(!$value || !$this->captcha->isValid($value)) {
			$opts['reuse'] = false;
			$value = '';
		}
		//image data
		$imgData = $this->captcha->render($opts);
		//create html
		$html  = $this->input($name, $value, [ 'autocomplete' => 'off' ]) . "\n";
		$html .= '<div class="captcha-image">' . "\n";
		$html .= '<img src="data:image/' . $opts['format'] . ';base64,' . base64_encode($imgData) . '">' . "\n";
		$html .= '</div>';
		//return
		return $html;
	}

	public static function formatAttr(array $opts) {
		//set vars
		$html = '';
		//loop through attr
		foreach($opts as $k => $v) {
			if($k && ($v || $v === 0 || $v === '0')) {
				//json encode?
				if(is_array($v)) {
					$v = json_encode($v);
				}
				//set value?
				if(is_string($v) || is_numeric($v)) {
					$v = str_replace('"', "'", $v);
					$html .= ' ' . $k . '="' . htmlentities($v, ENT_QUOTES, 'UTF-8') . '"';
				} else {
					$html .= ' ' . $k;
				}
			}
		}
		//return
		return $html;
	}

}