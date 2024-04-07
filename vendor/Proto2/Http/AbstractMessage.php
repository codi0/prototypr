<?php

namespace Proto2\Http;

//PSR-7 compatible
abstract class AbstractMessage {

	protected $headers = [];
	protected $stream = null;
	protected $protocol = '1.1';

	public function getProtocolVersion() {
		return $this->protocol;
	}

	public function withProtocolVersion($version) {
		$this->protocol = $version;
		return $this;
	}

	public function getHeaders() {
		return $this->headers;
	}

	public function hasHeader($header) {
		$header = $this->formatHeaderName($header);
		return isset($this->headerNames[$header]);
	}

	public function getHeader($header) {
		$header = $this->formatHeaderName($header);
		return isset($this->headers[$header]) ? $this->headers[$header] : [];
	}

	public function getHeaderLine($header) {
		return implode(', ', $this->getHeader($header));
	}

	public function withHeader($header, $value) {
		$header = $this->formatHeaderName($header);
		$value = $this->formatHeaderValue($value);
		$this->headers[$header] = $value;
		return $this;
	}

	public function withAddedHeader($header, $value) {
		$this->setHeaders([ $header => $value ]);
		return $this;
	}

	public function withoutHeader($header) {
		$header = $this->formatHeaderName($header);
		if(isset($this->headers[$header])) {
			unset($this->headers[$header]);
        }
		return $this;
	}

	public function getBody() {
		return $this->stream;
	}

	public function withBody($body) {
		$this->stream = is_object($body) ? $body : new Stream($body);
		return $this;
    }

	protected function setHeaders(array $headers) {
		foreach($headers as $header => $value) {
			$header = $this->formatHeaderName($header);
			$value = $this->formatHeaderValue($value);
			$this->headers[$header] = isset($this->headers[$header]) ? array_merge($this->headers[$header], $value) : $value;
		}
	}

	protected function formatHeaderName($name) {
		return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
	}

	protected function formatHeaderValue($value) {
		$res = [];
		foreach((array) $value as $v) {
			$res[] = trim((string) $v, " \t");
		}
		return $res;
	}

}