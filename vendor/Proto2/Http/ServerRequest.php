<?php

namespace Proto2\Http;

//PSR-7 compatible
class ServerRequest extends Request {

	protected $attributes = [];
	protected $cookieParams = [];
	protected $parsedBody;
	protected $queryParams = [];
	protected $serverParams;
	protected $uploadedFiles = [];

	public static function createFromGlobals($force = false) {
		static $request = null;
		if(!$request || $force) {
			$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
			$uri = Uri::createFromGlobals();
			$headers = self::getServerHeaders();
			$body = Stream::createFromGlobals();
			$protocol = isset($_SERVER['SERVER_PROTOCOL']) ? str_replace('HTTP/', '', $_SERVER['SERVER_PROTOCOL']) : '1.1';
			$request = new self($method, $uri, $headers, $body, $protocol, $_SERVER);
			$request = $request->withCookieParams($_COOKIE)->withQueryParams($_GET)->withParsedBody($_POST)->withUploadedFiles($_FILES)->withAttribute('master', true);
		}
		return $request;
	}

	public static function createFromRaw($message, $master=true) {
		$parsed = Parser::rawRequest($message);
		$request = new self($parsed['method'], $parsed['uri'], $parsed['headers'], $parsed['body'], $parsed['protocol'], $_SERVER);
		return $request->withCookieParams($parsed['cookies'])->withQueryParams($parsed['get'])->withParsedBody($parsed['body'])->withUploadedFiles($parsed['files'])->withAttribute('master', $master);
	}

	private static function getServerHeaders() {
		if(function_exists('getallheaders')) {
			return getallheaders();
		}
		$headers = [];
		foreach($_SERVER as $name => $value) {
			if(substr($name, 0, 5) == 'HTTP_') {
				$key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
				$headers[$key] = $value;
			}
		}
		return $headers;
	}

	public function __construct($method, $uri, array $headers=[], $body=null, $version='1.1', array $serverParams=[]) {
		parent::__construct($method, $uri, $headers, $body, $version);
        $this->serverParams = $serverParams;
	}

    public function getServerParams() {
		return $this->serverParams;
	}

	public function getServerParam($name, $default=null) {
		return isset($this->serverParams[$name]) ? $this->serverParams[$name] : $default;
	}

	public function getUploadedFiles() {
		return $this->uploadedFiles;
	}

	public function getUploadedFile($name, $default=null) {
		return isset($this->uploadedFiles[$name]) ? $this->uploadedFiles[$name] : $default;
	}

	public function withUploadedFiles(array $uploadedFiles) {
		$this->uploadedFiles = UploadedFile::formatFiles($uploadedFiles);
		return $this;
	}

	public function getCookieParams() {
		return $this->cookieParams;
	}

	public function getCookieParam($name, $default=null) {
		return isset($this->cookieParams[$name]) ? $this->cookieParams[$name] : $default;
	}

	public function withCookieParams(array $cookies) {
		$this->cookieParams = $cookies;
		return $this;
	}

	public function getQueryParams() {
		return $this->queryParams;
	}

	public function getQueryParam($name, $default=null) {
		return isset($this->queryParams[$name]) ? $this->queryParams[$name] : $default;
	}

	public function withQueryParams(array $query) {
		$this->queryParams = $query;
		return $this;
	}

	public function getParsedBody() {
		return $this->parsedBody;
	}

	public function getParsedBodyParam($name, $default=null) {
		return isset($this->parsedBody[$name]) ? $this->parsedBody[$name] : $default;
	}

	public function withParsedBody($data) {
		if(!is_array($data) && !is_object($data) && !is_null($data)) {
			throw new \InvalidArgumentException('Invalid parsed body data type');
		}
		$this->parsedBody = $data;
		return $this;
	}

	public function getAttributes() {
		return $this->attributes;
	}

	public function getAttribute($name, $default=null) {
		return isset($this->attributes[$name]) ? $this->attributes[$name] : $default;
	}

	public function withAttribute($name, $value) {
		$this->attributes[$name] = $value;
		return $this;
	}

	public function withoutAttribute($name) {
		if(array_key_exists($name, $this->attributes)) {
			 unset($this->attributes[$name]);
		}
		return $this;
	}

}