<?php

namespace Proto2\Http;

//PSR-7 compatible
class Stream {

    protected $stream;
    protected $seekable;
	protected $readable;
	protected $writable;
	protected $uri;
	protected $size;

	protected $readWriteHash = [
		'read' => [
			'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true,
			'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true,
			'c+b' => true, 'rt' => true, 'w+t' => true, 'r+t' => true,
			'x+t' => true, 'c+t' => true, 'a+' => true,
		],
		'write' => [
			'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true,
			'c+' => true, 'wb' => true, 'w+b' => true, 'r+b' => true,
			'x+b' => true, 'c+b' => true, 'w+t' => true, 'r+t' => true,
			'x+t' => true, 'c+t' => true, 'a' => true, 'a+' => true,
		],
	];

	public static function getRawBody() {
		static $body = null;
		//get body?
		if($body === null) {
			$body = file_get_contents('php://input') ?: '';
		}
		//return
		return $body;
	}

	public static function createFromGlobals() {
		return new self(self::getRawBody());
	}

	public function __construct($body='') {
		$body = $body ?: '';
		if($body instanceof self) {
			$body = $body->stream;
		}
		if(is_string($body)) {
			$resource = fopen('php://temp', 'r+');
			fwrite($resource, $body);
			$body = $resource;
		}
		if(!is_resource($body)) {
			throw new \InvalidArgumentException('Stream body must be a string or resource');
		}
		$this->stream = $body;
		$meta = stream_get_meta_data($this->stream);
		$this->seekable = $meta['seekable'] && (fseek($this->stream, 0, SEEK_CUR) === 0);
		$this->readable = isset($this->readWriteHash['read'][$meta['mode']]);
		$this->writable = isset($this->readWriteHash['write'][$meta['mode']]);
		$this->uri = $this->getMetadata('uri');
	}

	public function __destruct() {
		$this->close();
	}

    public function __toString() {
		try {
			if($this->isSeekable()) {
				$this->seek(0);
			}
			return $this->getContents();
		} catch (\Exception $e) {
			return '';
		}
	}

    public function getMetadata($key=null) {
		if(!$this->stream) {
			return $key ? null : [];
		}
		$meta = stream_get_meta_data($this->stream);
		return is_null($key) ? $meta : (isset($meta[$key]) ? $meta[$key] : null);
	}

	public function getSize() {
		if($this->size !== null) {
			return $this->size;
		}
		if(!$this->stream) {
			return null;
		}
		if($this->uri) {
			clearstatcache(true, $this->uri);
		}
		$stats = fstat($this->stream);
		if(isset($stats['size'])) {
			$this->size = $stats['size'];
			return $this->size;
		}
		return null;
	}

	public function tell() {
		return $this->stream ? ftell($this->stream) : 0;
	}

	public function eof() {
		return !$this->stream || feof($this->stream);
	}

	public function isSeekable() {
		return $this->seekable;
	}

	public function seek($offset, $whence=SEEK_SET) {
		if(!$this->seekable) {
			throw new \RuntimeException('Stream is not seekable');
		}
		if(fseek($this->stream, $offset, $whence) === -1) {
			throw new \RuntimeException('Unable to seek to stream position ' . $offset . ' with whence ' . var_export($whence, true));
		}
		return $this;
	}

	public function rewind() {
		return $this->seek(0);
	}

	public function isReadable() {
		return $this->readable;
	}

	public function isWritable() {
		return $this->writable;
	}

	public function read($length) {
		if(!$this->readable) {
			throw new \RuntimeException('Cannot read from non-readable stream');
		}
		return fread($this->stream, $length);
    }

	public function getContents() {
		if(!$this->stream) {
			throw new \RuntimeException('Unable to read stream contents');
		}
		if(($contents = stream_get_contents($this->stream)) === false) {
			throw new \RuntimeException('Unable to read stream contents');
		}
		return $contents;
	}

	public function write($string) {
		if(!$this->writable) {
			throw new \RuntimeException('Cannot write to a non-writable stream');
		}
		$this->size = null;
		if(($result = fwrite($this->stream, $string ?: '')) === false) {
			throw new \RuntimeException('Unable to write to stream');
		}
		return $result;
	}

	public function truncate($length=0) {
		if(!$this->writable) {
			throw new \RuntimeException('Cannot write to a non-writable stream');
		}
		return ftruncate($this->stream, $length);
	}

	public function detach() {
		if(!$this->stream) {
			return null;
		}
		$result = $this->stream;
		$this->stream = $this->size = $this->uri = null;
		$this->readable = $this->writable = $this->seekable = false;
		return $result;
	}

	public function close() {
		if($this->stream && is_resource($this->stream)) {
			fclose($this->stream);
		}
		$this->detach();
	}

}