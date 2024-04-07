<?php

namespace Proto2\Http;

//PSR-7 compatible
class Request extends AbstractMessage {

    protected $method;
	protected $requestTarget;
    protected $uri;

	public function __construct($method, $uri, array $headers=[], $body=null, $version='1.1') {
		$this->method = $method;
		$this->uri = is_object($uri) ? $uri : new Uri($uri);
		$this->setHeaders($headers);
		$this->stream = is_object($body) ? $body : new Stream($body);
		$this->protocol = $version;
		$this->updateTargetFromUri();
		if(!$this->hasHeader('Host')) {
			$this->updateHostFromUri();
		}
    }

	public function createResponse($code=200, $reasonPhrase='') {
		return new Response($code, [], null, '1.1', $reasonPhrase);
	}

	public function getRequestTarget() {
		return $this->requestTarget;
	}

	public function withRequestTarget($requestTarget) {
		$this->requestTarget = $requestTarget;
		return $this;
	}

	public function getMethod() {
		return $this->method;
	}

	public function withMethod($method) {
		$this->method = $method;
		return $this;
	}

	public function getUri() {
		return $this->uri;
	}

    public function withUri($uri, $preserveHost=false) {
		$this->uri = is_object($uri) ? $uri : new Uri($uri);
		$this->updateTargetFromUri();
		if(!$preserveHost || !$this->hasHeader('Host')) {
			$this->updateHostFromUri();
		}
		return $this;
	}

	protected function updateHostFromUri() {
        if(!$host = $this->uri->getHost()) {
			return;
		}
		if($port = $this->uri->getPort()) {
			$host .= ':' . $port;
		}
		$this->headers = [ 'Host' => [ $host ] ] + $this->headers;
	}

	protected function updateTargetFromUri() {
		if(!$target = $this->uri->getPath()) {
			$target = '/';
		}
		if($query = $this->uri->getQuery()) {
			$target .= '?' . $query;
		}
		$this->requestTarget = $target;
	}

}