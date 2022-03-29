<?php

namespace Prototypr;

class Html {

	protected $kernel;

	public function __construct(array $opts=[], $merge=true) {
		//set opts
		foreach($opts as $k => $v) {
			//property exists?
			if(property_exists($this, $k)) {
				//is array?
				if($merge && $this->$k === (array) $this->$k) {
					$this->$k = array_merge($this->$k, $v);
				} else {
					$this->$k = $v;
				}
			}
		}
	}

	public function input($name, $value='', array $opts=[]) {
		//set opts
		$opts = array_merge([
			'type' => 'text',
			'name' => ($name === 'submit') ? '' : $name,
			'value' => $value,
		], $opts);
		//return
		return '<input' . $this->formatAttr($opts) . '>';
	}

	public function text($name, $value='', array $opts=[]) {
		return $this->input($name, $value, array_merge([ 'type' => 'text' ], $opts));
	}

	public function hidden($name, $value='', array $opts=[]) {
		return $this->input($name, $value, array_merge([ 'type' => 'hidden' ], $opts));
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

	public function file($name, $value='', array $opts=[]) {
		return $this->input($name, $value, array_merge([ 'type' => 'file' ], $opts));
	}

	public function button($name, $value='', array $opts=[]) {
		return $this->input($name, $value, array_merge([ 'type' => 'button' ], $opts));
	}

	public function submit($name, $value='', array $opts=[]) {
		return $this->input($name, $value, array_merge([ 'type' => 'submit' ], $opts));
	}

	public function textarea($name, $value='', array $opts=[]) {
		//set opts
		$opts = array_merge([
			'name' => $name,
		], $opts);
		//return
		return '<textarea' . $this->formatAttr($opts) . '>' . $value . '</textarea>';
	}

	public function checkbox($name, $value='', array $opts=[]) {
		//format attr
		$opts = array_merge([
			'type' => 'checkbox',
			'options' => [ $name ],
		], $opts);
		//format value
		$value = (string) $value;
		//open html
		$html = '<span class="' . $opts['type'] . '-group">';
		//loop through options
		foreach($opts['options'] as $key => $label) {
			//set name
			$n = $name;
			$id = $name . '-' . $key;
			//set value
			if($opts['type'] === 'radio') {
				$v = (string) $key;
			} else {
				$v = '1';
			}
			//is array?
			if(count($opts) > 1 && $opts['type'] !== 'radio') {
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
		//set default value?
		if($value === '' || $value === null) {
			$value = array_keys($opts['options']);
			$value = $value ? $value[0] : '';
		}
		//open select
		$html = '<select' . $this->formatAttr($opts) . '>' . "\n";
		//loop through options
		foreach($opts['options'] as $key => $val) {
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
			if($this->kernel) {
				$k = $this->kernel->url($k);
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
		if(!$this->kernel || !isset($this->kernel->captcha)) {
			throw new \Exception('No captcha service available');
		}
		//reuse captcha?
		if(!$value || !$this->kernel->captcha->isValid($value)) {
			$opts['reuse'] = false;
			$value = '';
		}
		//image data
		$imgData = $this->kernel->captcha->render($opts);
		//create html
		$html  = $this->input($name, $value, [ 'autocomplete' => 'off' ]) . "\n";
		$html .= '<div class="captcha-image">' . "\n";
		$html .= '<img src="data:image/' . $opts['format'] . ';base64,' . base64_encode($imgData) . '">' . "\n";
		$html .= '</div>';
		//return
		return $html;
	}

	protected function formatAttr(array $opts) {
		//set vars
		$html = '';
		//loop through attr
		foreach($opts as $k => $v) {
			if($k && $v && is_scalar($v)) {
				$html .= ' ' . $k . '="' . $v . '"';
			}
		}
		//return
		return $html;
	}

	protected function createUrl($path) {
		//create url?
		if($this->createUrl) {
			$func = $this->createUrl;
			$path = $func($path) ?: $path;
		}
		//return
		return $path;
	}

}