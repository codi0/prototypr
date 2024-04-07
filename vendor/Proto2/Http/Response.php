<?php

namespace Proto2\Http;

//PSR-7 compatible
class Response extends AbstractMessage {

	protected $type = '';
	protected $statusCode;
	protected $reasonPhrase = '';

	protected $phrases = [
		100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing',
		200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-status', 208 => 'Already Reported',
		300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 306 => 'Switch Proxy', 307 => 'Temporary Redirect',
		400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Time-out', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Large', 415 => 'Unsupported Media Type', 416 => 'Requested range not satisfiable', 417 => 'Expectation Failed', 418 => 'I\'m a teapot', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Unordered Collection', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
		500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Time-out', 505 => 'HTTP Version not supported', 506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected', 511 => 'Network Authentication Required',
	];

	public function __construct($status=200, array $headers=[], $body=null, $version='1.1', $reasonPhrase='') {
		$this->withStatus($status, $reasonPhrase);
		$this->setHeaders($headers);
		$this->stream = is_object($body) ? $body : new Stream($body);
		$this->protocol = $version;
	}

	public function __toString() {
		return $this->send(true);
	}

	public function getMediaType() {
		if($this->type) {
			return $this->type;	
		}
		if(!$contentType = $this->getHeaderLine('Content-Type')) {
			foreach(headers_list() as $header) {
				if(stripos($header, 'content-type') === 0) {
					$contentType = $header;
					break;
				}
			}
		}
		if($contentType) {
			$type = str_replace('Content-Type:', '', $contentType);
			$type = explode(';', $type);
			$type = explode('/', $type[0]);
			$this->type = strtolower(trim($type[1]));
		}
		return $this->type ?: 'html';
	}

	public function getReasonPhrase() {
		return $this->reasonPhrase;
	}

	public function getStatusCode() {
		return $this->statusCode;
	}

	public function withStatus($code, $reasonPhrase='') {
		$code = (int) $code;
		if($code < 100 || $code > 599) {
			throw new \InvalidArgumentException('Status code must be an integer between 100 and 599');
		}
		if(!$reasonPhrase && isset($this->phrases[$code])) {
			$reasonPhrase = $this->phrases[$code];
		}
		$this->statusCode = $code;
		$this->reasonPhrase = $reasonPhrase;
        return $this;
    }

	public function read($position=0) {
		if($contents = $this->getBody()->seek($position)->getContents()) {
			if(strpos($contents, ':::') === 0) {
				$contents = unserialize(substr($contents, 3));
			}
		}
		return $contents;
	}

	public function write($output, $replace=true) {
		if(!is_scalar($output)) {
			$output = ':::' . serialize($output);
		}
		if($replace) {
			$this->getBody()->truncate();
		}
		$this->getBody()->write($output);
		return $this;
	}

	public function send($ret=false) {
		if(!headers_sent()) {
			header("HTTP/" . $this->protocol . " " . $this->statusCode . " " . $this->reasonPhrase);
			foreach($this->headers as $name => $values) {
				header($name . ": " . implode(", ", $values));
			}
		}
		if($ret) {
			return $this->read();
		} else {
			echo $this->read();
		}
	}

	public function getRaw() {
		$body = $this->read();
		$msg = "HTTP/" . $this->protocol . " " . $this->statusCode . " " . $this->reasonPhrase . "\r\n";
		$this->headers['Content-Length'] = [ strlen($body) ];
		foreach($this->headers as $name => $values) {
			$msg .= $name . ": " . implode(", ", $values) . "\r\n";
		}
		$msg .= "\r\n";
		$msg .= $body;
		return $msg;
	}

}