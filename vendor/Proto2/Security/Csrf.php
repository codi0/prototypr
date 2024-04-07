<?php

namespace Proto2\Security;

class Csrf {

	protected $crypt = null;
	protected $session = null;

	protected $injectHead = false;
	protected $perRequest = false;

	protected $host = '';
	protected $field = '_csrf';
	protected $methods = [ 'POST', 'PUT', 'PATCH', 'DELETE' ];

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

	public function getToken($regen=false) {
		//create new token?
		if($regen || !$this->session->get('csrf_token')) {
			$this->session->set('csrf_token', $this->crypt->nonce(32));
		}
		//return
		return $this->session->get('csrf_token');
	}

	public function verifyToken() {
		//is protected method?
		if(!in_array($_SERVER['REQUEST_METHOD'], $this->methods)) {
			return true;
		}
		//check token
		return isset($_POST[$this->field]) && $_POST[$this->field] === $this->getToken();
	}

	public function sameOrigin($anyMethod=false) {
		//is protected method?
		if(!$anyMethod && !in_array($_SERVER['REQUEST_METHOD'], $this->methods)) {
			return true;
		}
		//set vars
		$origHost = '';
		//get origin host
		if(isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN']) {
			$origHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
		} elseif(isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER']) {
			$origHost = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
		}
		//check host matches
		return $_SERVER['HTTP_HOST'] === $origHost;
	}

	public function injectHtml($content) {
		//set vars
		$token = '';
		$methods = $this->methods;
		$host = $this->host ?: $_SERVER['HTTP_HOST'];
		$isHtml = strip_tags($content) !== $content;
		//valid token sent?
		if(!$this->verifyToken()) {
			if($isHtml) {
				header('Location: ' . $_SERVER['REQUEST_URI']);
			} else {
				header('HTTP/1.0 404 Not Found');
				echo 'Invalid csrf token';
			}
			exit();
		}
		//inject token in <head> tag?
		if($isHtml && $this->injectHead && stripos($content, '</head>') !== false) {
			//get token
			$token = $token ?: $this->getToken($this->perRequest);
			//create meta tag
			$metaTag = '<meta name="' . $this->field . '" content="' . $token . '">';
			//update content
			$content = str_ireplace('</head>', $metaTag . "\n" . '</head>', $content);
		}
		//inject token after <form> tags?
		if($isHtml && stripos($content, '<form') !== false) {
			//get token
			$token = $token ?: $this->getToken($this->perRequest);
			//create input tag
			$inputTag = '<input type="hidden" name="' . $this->field . '" value="' . $token . '">';
			//update content
			$content = preg_replace_callback('/<form(>|.*?[^?]>)/i', function($matches) use($methods, $host, $inputTag) {
				//analyse attributes
				$dom = new \DOMDocument();
				$dom->loadHTML($matches[0]);
				$tags = $dom->getElementsByTagName('form');
				$formMethod = strtoupper($tags[0]->getAttribute('method'));
				$formHost = parse_url($tags[0]->getAttribute('action'), PHP_URL_HOST);
				//matching method?
				if(!$formMethod || !in_array($formMethod, $methods)) {
					return $matches[0];
				}
				//matching host?
				if($host && $formHost && strpos(strrev($formHost), strrev($host)) !== 0) {
					return $matches[0];
				}
				//add token
				return $matches[0] . "\n" . $inputTag;
			}, $content);
		}
		//return
		return $content;
	}

}