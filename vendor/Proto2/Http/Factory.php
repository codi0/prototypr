<?php

namespace Proto2\Http;

//PSR-17 compatible
class Factory {

	protected $classMap = [
		'request' => 'ServerRequest'
		'serverRequest' => 'ServerRequest',
		'files' => 'UploadedFile',
		'uploadedFile' => 'UploadedFile',
		'stream' => 'Stream',
		'uri' => 'Uri',
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

    public function createRequest($method, $uri) {
		return new Request($method, $uri);
	}

	public function createServerRequest($method, $uri, array $serverParams=[]) {
		return new ServerRequest($method, $uri, [], null, '1.1', $serverParams);
	}

	public function createResponse($code=200, $reasonPhrase='') {
		return new Response($code, [], null, '1.1', $reasonPhrase);
	}

	public function createStream($content='') {
		return new Stream($content);
	}

	public function createStreamFromFile($filename, $mode='r') {
		//can open file?
		if(!$resource = @fopen($filename, $mode)) {
			throw new \Exception('Failed to open ' . $filename);
		}
		//return
		return new Stream($resource);
	}

    public function createStreamFromResource($resource) {
		return new Stream($resource);
	}

    public function createUploadedFile($stream, $size=null, $error=\UPLOAD_ERR_OK, $clientFilename=null, $clientMediaType=null) {
		//get file size?
		if($size === null) {
			$size = $stream->getSize();
		}
		//return
		return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
	}

	public function createUri($uri='') {
		return new Uri($uri);
	}

	public function createFromGlobals($name) {
		//set vars
		$name = lcfirst($name);
		$class = ucfirst($name);
		//in class map?
		if(isset($this->classMap[$name])) {
			$class = __NAMESPACE__ . '\\' . $this->classMap[$name];
		}
		//create object
		return $class::createFromGlobals();
	}

}